<?php

declare(strict_types=1);

namespace JobWarden\Batch;

use JobWarden\Events\BatchStateChanged;
use JobWarden\Events\JobStateChanged;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Derives a batch's lifecycle from its members (spec §8). Reacts to each member
 * reaching a terminal state (via JobStateChanged, after commit):
 *  - fail_fast / threshold: a member failure cancels the remaining members and
 *    fails the batch eagerly;
 *  - completion: once no member is in-flight, the batch becomes succeeded (all
 *    ok) or partial (some failed/canceled).
 * Progress counters are maintained transactionally by the StateMachine; the
 * batch transition itself is a guarded UPDATE so concurrent finalizers are safe.
 */
final class BatchCoordinator
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function onJobStateChanged(JobStateChanged $event): void
    {
        $batchId = $event->job->getAttribute('batch_id');
        if ($batchId === null || ! $event->to->isTerminal()) {
            return;
        }

        $batch = Batch::find($batchId);
        if ($batch === null || $batch->state->isTerminal()) {
            return;
        }

        if ($event->to === JobState::Failed && $this->shouldEagerFail($batch)) {
            // Fail the batch FIRST so the member cancellations below (which fire
            // their own completion checks) see a terminal batch and no-op.
            $this->transitionBatch($batch, BatchState::Failed, "member failed ({$batch->failure_policy})");
            $this->cancelRemainingMembers($batch, "batch member failed under {$batch->failure_policy}");

            return;
        }

        // A member that ended NON-success makes its dependents unreachable (deps
        // are strict: all must succeed). Cancel them so the batch can complete
        // (partial) instead of hanging on stranded `pending` members. Each
        // cancellation cascades transitively via its own event.
        if (in_array($event->to, [JobState::Failed, JobState::Canceled, JobState::Stopped], true)) {
            $this->cancelUnreachableDependents($event->job->id, $batch);
        }

        $this->maybeComplete($batch);
    }

    /** Cancel a whole batch — propagates to every non-terminal member (spec §8.3). */
    public function cancel(Batch $batch, string $reason, ?string $actorId = null): void
    {
        // Mark the batch canceled FIRST so member cancellations don't race it to
        // a `partial` completion.
        $this->transitionBatch($batch, BatchState::Canceled, $reason);
        $this->cancelRemainingMembers($batch, $reason);
    }

    private function shouldEagerFail(Batch $batch): bool
    {
        return match ($batch->failure_policy) {
            'fail_fast' => true,
            'threshold' => (int) $batch->failed_count > (int) ($batch->failure_threshold ?? 0),
            default => false, // continue
        };
    }

    private function maybeComplete(Batch $batch): void
    {
        $batch->refresh();
        if ($batch->state->isTerminal()) {
            return;
        }

        $inFlight = (int) $batch->pending_count + (int) $batch->running_count;
        if ($inFlight > 0) {
            return; // members still executing
        }

        $clean = (int) $batch->failed_count === 0 && (int) $batch->canceled_count === 0;
        $this->transitionBatch($batch, $clean ? BatchState::Succeeded : BatchState::Partial, 'all members terminal');
    }

    private function cancelRemainingMembers(Batch $batch, string $reason): void
    {
        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];

        $members = Job::query()->where('batch_id', $batch->id)->whereNotIn('state', $terminal)->get();
        foreach ($members as $job) {
            $this->cancelMember($job, $reason);
        }
    }

    /** Cancel the direct dependents of a non-succeeding job; each cascades further via its own event. */
    private function cancelUnreachableDependents(mixed $upstreamId, Batch $batch): void
    {
        $dependentIds = $this->connection()->table($this->tbl('job_dependencies'))
            ->where('depends_on_job_id', $upstreamId)
            ->pluck('job_id');

        foreach ($dependentIds as $id) {
            $dep = Job::find($id);
            if ($dep === null || (string) $dep->batch_id !== (string) $batch->id) {
                continue;
            }
            if (in_array($dep->state, [JobState::Pending, JobState::Queued, JobState::Retrying], true)) {
                $this->cancelMember($dep, 'unreachable: an upstream dependency did not succeed');
            }
        }
    }

    private function cancelMember(Job $job, string $reason): void
    {
        $conn = $this->connection();
        $now = $conn->raw(SqlTime::nowExpr($conn));
        $conn->table($this->tbl('jobs'))->where('id', $job->id)->update([
            'cancel_requested' => true,
            'cancel_mode' => 'cancel',
            'cancel_reason' => $reason,
            'cancel_requested_at' => $now,
            'updated_at' => $now,
        ]);
        $job->refresh();

        try {
            // Pre-run members cancel immediately; running/orphaned ones get the
            // flag and are stopped by their supervisor/reaper (cross-host).
            if (in_array($job->state, [JobState::Pending, JobState::Queued, JobState::Retrying], true)) {
                $this->stateMachine->applyJobTransition($job, JobState::Canceled, TransitionContext::for(ActorType::System, null, $reason));
            }
        } catch (IllegalTransitionException|GuardFailedException|StaleFencingTokenException) {
            // raced with a claim/transition — the desired-state flag remains.
        }
    }

    private function transitionBatch(Batch $batch, BatchState $to, ?string $reason): void
    {
        $from = $batch->state;
        if ($from->isTerminal()) {
            return;
        }

        // Guarded UPDATE: only one concurrent finalizer wins.
        $conn = $this->connection();
        $now = $conn->raw(SqlTime::nowExpr($conn));
        $affected = $conn->table($this->tbl('batches'))
            ->where('id', $batch->id)
            ->where('state', $from->value)
            ->update([
                'state' => $to->value,
                'summary' => json_encode($this->summary($batch->refresh(), $to), JSON_THROW_ON_ERROR),
                'finished_at' => $to->isTerminal() ? $now : $batch->finished_at,
                'updated_at' => $now,
            ]);

        if ($affected === 1) {
            $batch->state = $to;
            event(new BatchStateChanged($batch, $from, $to, $reason));
        }
    }

    /** @return array<string,mixed> */
    private function summary(Batch $batch, BatchState $to): array
    {
        return [
            'outcome' => $to->value,
            'total' => (int) $batch->total_jobs,
            'succeeded' => (int) $batch->succeeded_count,
            'failed' => (int) $batch->failed_count,
            'canceled' => (int) $batch->canceled_count,
            'failure_policy' => $batch->failure_policy,
        ];
    }

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
