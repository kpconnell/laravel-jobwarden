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
use Illuminate\Support\Facades\Log;

/**
 * Derives a batch's lifecycle from its members (spec §8). Reacts to each member
 * reaching a terminal state (via JobStateChanged, after commit):
 *  - fail_fast / threshold: a member failure cancels the remaining members and
 *    fails the batch eagerly;
 *  - completion: once no member is in-flight, the batch becomes succeeded (all
 *    ok) or partial (some failed/canceled).
 * Progress counters are maintained transactionally by the StateMachine; the
 * batch transition itself is a guarded UPDATE so concurrent finalizers are safe.
 *
 * The cascade also runs in reverse: when a doomed member re-enters the DAG
 * (operator retry/restart), a partial/failed batch reopens and the dependents
 * the system canceled as unreachable are revived to `pending` — back to
 * waiting on their dependencies.
 *
 * Every unreachability rule below is scoped to `on_success` edges. A member
 * joined by an `on_completion` edge is reachable BECAUSE its upstream ended:
 * it is never canceled as unreachable, never counts as stranded, and never
 * blocks a revival. A dependent with a mix of both is unreachable only when one
 * of its on_success upstreams is doomed.
 */
final class BatchCoordinator
{
    /**
     * Terminal member states that make dependents unreachable — across an
     * `on_success` edge only (see the class note).
     */
    private const DOOMED = [JobState::Failed, JobState::Canceled, JobState::Stopped];

    /**
     * The cancel_reason stamped by the unreachable-dependents cascade. Revival
     * matches on it exactly so it only ever undoes the system's own cascade,
     * never an operator's cancel verdict.
     */
    private const UNREACHABLE_REASON = 'unreachable: an upstream dependency did not succeed';

    /**
     * Batch states in which a member canceled as unreachable may be revived —
     * the EXACT inverse of the states strandedMemberIds() cancels in, and it
     * must stay that way. `failed` belongs here for the same reason it belongs
     * there: an eagerly-failed batch keeps its spared finalizer subtree in
     * flight, so a retried member inside that subtree has to be able to bring
     * its own dependents back. Cancel reachable but revive not would strand
     * them permanently, on a batch whose tripped policy stops it reopening.
     */
    private static function acceptsRevival(BatchState $state): bool
    {
        return in_array($state, [BatchState::Running, BatchState::Failed], true);
    }

    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function onJobStateChanged(JobStateChanged $event): void
    {
        $batchId = $event->job->getAttribute('batch_id');
        if ($batchId === null) {
            return;
        }

        $reentered = ! $event->to->isTerminal() && in_array($event->from, self::DOOMED, true);
        if (! $event->to->isTerminal() && ! $reentered) {
            return;
        }

        $batch = Batch::find($batchId);
        if ($batch === null) {
            return;
        }

        if ($reentered) {
            // A doomed member re-entered the DAG (operator retry/restart, or a
            // revival cascading below): reopen a completed batch and put the
            // dependents its doom canceled back to waiting on it. Each revival
            // cascades transitively via its own event.
            $this->reopenBatch($batch);
            if (self::acceptsRevival($batch->state)) {
                $this->reviveUnreachableDependents($event->job->id, $batch);
            }

            return;
        }

        if ($batch->state->isTerminal()) {
            // `failed` ONLY: that is the one terminal state that can still have
            // members in flight, because the eager sweep spared a finalizer
            // subtree. The verdict is settled but the DAG rules INSIDE that
            // subtree still apply — without this a dependent of a failed
            // finalizer would sit pending forever on a batch nothing will ever
            // complete again. Every other terminal state is excluded on
            // purpose: cancel/stop already cancel every member, so the cascade
            // would find nothing to do and would only re-walk the whole graph
            // one synchronous event-recursion per edge (measured: +66% queries
            // and recursion to chain depth on a cancelBatch of a long chain).
            if ($batch->state === BatchState::Failed && in_array($event->to, self::DOOMED, true)) {
                $this->cancelUnreachableDependents($event->job->id, $batch);
            }

            return;
        }

        if ($event->to === JobState::Failed && $this->shouldEagerFail($batch)) {
            // Fail the batch FIRST so the member cancellations below (which fire
            // their own completion checks) see a terminal batch and no-op.
            $this->transitionBatch($batch, BatchState::Failed, "member failed ({$batch->failure_policy})");
            $this->cancelRemainingMembers($batch, "batch member failed under {$batch->failure_policy}", sparingFinalizers: true);

            return;
        }

        // A member that ended NON-success makes its dependents unreachable (deps
        // are strict: all must succeed). Cancel them so the batch can complete
        // (partial) instead of hanging on stranded `pending` members. Each
        // cancellation cascades transitively via its own event.
        if (in_array($event->to, self::DOOMED, true)) {
            $this->cancelUnreachableDependents($event->job->id, $batch);
        }

        $this->maybeComplete($batch);
    }

    /**
     * The event-loss backstop (leader-only, called from the global reaper's
     * tick). Batch lifecycle normally advances via after-commit JobStateChanged
     * listeners — and a process that dies between a member's commit and its
     * listener loses that event FOREVER: a batch is left `running` with nothing
     * in flight, an eager failure policy goes unapplied, or dependents sit
     * `pending` behind an upstream that can no longer succeed. Everything the
     * lost event would have decided is re-derivable from the counters (which the
     * StateMachine maintains transactionally, not via events) and the dependency
     * table, so this sweep re-derives it. Every action funnels through the same
     * guarded writes the live path uses, so racing a live listener is harmless —
     * one writer wins, the other no-ops.
     */
    public function reconcile(int $limit = 200): void
    {
        // Lost re-entry events first: a retried/restarted member whose event was
        // lost leaves its completed batch terminal with work in flight, and its
        // canceled-as-unreachable dependents unrevived. Reopen, then revive —
        // each revival re-fires the live cascade for its own dependents.
        foreach ($this->reopenableBatchIds($limit) as $id) {
            $batch = Batch::find($id);
            // reopenBatch refuses (quietly) while a still-tripped failure
            // policy keeps the batch failed despite the re-entered member.
            if ($batch === null || ! $this->reopenBatch($batch)) {
                continue;
            }

            Log::warning('batch reconcile: reopened a completed batch whose member re-entered the DAG', [
                'role' => 'batch_reconcile',
                'batch_id' => (string) $batch->id,
            ]);
        }

        foreach ($this->revivableMemberIds($limit) as $id) {
            $job = Job::find($id);
            if ($job === null) {
                continue;
            }

            Log::warning('batch reconcile: reviving a canceled member whose upstreams are viable again', [
                'role' => 'batch_reconcile',
                'job_id' => (string) $job->id,
                'batch_id' => (string) $job->batch_id,
            ]);
            $this->reviveMember($job);
        }

        // Stranded dependents next: their cancellation updates the counters that
        // the completion sweep below reads, so one pass converges a lost-event
        // chain (upstream failed → dependent canceled → batch partial).
        foreach ($this->strandedMemberIds($limit) as $id) {
            $job = Job::find($id);
            if ($job === null) {
                continue;
            }

            Log::warning('batch reconcile: canceling a member stranded behind a non-succeeded dependency', [
                'role' => 'batch_reconcile',
                'job_id' => (string) $job->id,
                'batch_id' => (string) $job->batch_id,
            ]);
            $this->cancelMember($job, self::UNREACHABLE_REASON);
        }

        foreach ($this->reconcilableBatchIds($limit) as $id) {
            $batch = Batch::find($id);
            if ($batch === null || $batch->state->isTerminal()) {
                continue; // healed by a live listener in the meantime
            }

            Log::warning('batch reconcile: healing a batch whose member event was lost', [
                'role' => 'batch_reconcile',
                'batch_id' => (string) $batch->id,
                'failure_policy' => $batch->failure_policy,
            ]);

            if ($this->shouldEagerFail($batch)) {
                $this->transitionBatch($batch, BatchState::Failed, "member failed ({$batch->failure_policy})");
                $this->cancelRemainingMembers($batch, "batch member failed under {$batch->failure_policy}", sparingFinalizers: true);

                continue;
            }

            $this->maybeComplete($batch);
        }
    }

    /** Cancel a whole batch — propagates to every non-terminal member (spec §8.3). */
    public function cancel(Batch $batch, string $reason, ?string $actorId = null): void
    {
        // Mark the batch canceled FIRST so member cancellations don't race it to
        // a `partial` completion.
        $this->transitionBatch($batch, BatchState::Canceled, $reason);
        $this->cancelRemainingMembers($batch, $reason);
    }

    /**
     * Counter-based (mirrors reconcilableBatchIds' SQL) rather than "a failure
     * event just happened": a retry decrements failed_count, and reopenBatch
     * relies on that to tell a still-tripped policy from a repaired one.
     */
    private function shouldEagerFail(Batch $batch): bool
    {
        return match ($batch->failure_policy) {
            'fail_fast' => (int) $batch->failed_count > 0,
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

    /**
     * @param  bool  $sparingFinalizers  exempt the finalizer closure (see below).
     *                                    Set by the EAGER-FAILURE sweep only: an
     *                                    operator canceling the whole batch means
     *                                    "stop everything", finalizers included.
     */
    private function cancelRemainingMembers(Batch $batch, string $reason, bool $sparingFinalizers = false): void
    {
        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];
        $spared = $sparingFinalizers ? $this->finalizerClosure($batch) : [];

        $members = Job::query()->where('batch_id', $batch->id)->whereNotIn('state', $terminal)->get();
        foreach ($members as $job) {
            if (isset($spared[(string) $job->id])) {
                continue;
            }
            $this->cancelMember($job, $reason);
        }
    }

    /**
     * Members an eager failure policy must NOT cancel: those joined by an
     * `on_completion` edge — a finalizer exists precisely to run when its
     * upstream did not succeed, so cancelling it would withhold the feature in
     * the one case it is most needed — plus everything downstream of one, so a
     * "clean up, then notify" tail survives with it.
     *
     * The batch still fails IMMEDIATELY; a spared finalizer runs on afterwards
     * (admission and claiming are batch-state-agnostic), exactly as a running
     * member that was only flagged does today. Inside the spared subtree the
     * ordinary DAG rules resume: if the finalizer itself ends non-success, the
     * unreachable-cascade cancels its on_success dependents like any other.
     *
     * @return array<string,true> job id => true
     */
    private function finalizerClosure(Batch $batch): array
    {
        $edges = $this->connection()->table($this->tbl('job_dependencies').' as d')
            ->join($this->tbl('jobs').' as j', 'j.id', '=', 'd.job_id')
            ->where('j.batch_id', $batch->id)
            ->get(['d.job_id', 'd.depends_on_job_id', 'd.edge_condition']);

        $children = [];
        $closure = [];
        foreach ($edges as $edge) {
            $children[(string) $edge->depends_on_job_id][] = (string) $edge->job_id;
            if ($edge->edge_condition === 'on_completion') {
                $closure[(string) $edge->job_id] = true;
            }
        }

        // BFS the descendants of every finalizer (pure PHP over the batch's own
        // edges, like BatchDag — no recursive CTE, identical on every engine).
        $queue = array_keys($closure);
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($children[$queue[$i]] ?? [] as $child) {
                if (! isset($closure[$child])) {
                    $closure[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $closure;
    }

    /** Cancel the direct dependents of a non-succeeding job; each cascades further via its own event. */
    private function cancelUnreachableDependents(mixed $upstreamId, Batch $batch): void
    {
        // Everything EXCEPT on_completion, rather than on_success explicitly, so
        // an unrecognized edge_condition behaves as on_success here just as it
        // does in DepsSatisfiedGuard — a condition that gated admission but
        // escaped this cascade would strand its dependent and hang the batch.
        $dependentIds = $this->connection()->table($this->tbl('job_dependencies'))
            ->where('depends_on_job_id', $upstreamId)
            ->where('edge_condition', '!=', 'on_completion')
            ->pluck('job_id');

        foreach ($dependentIds as $id) {
            $dep = Job::find($id);
            if ($dep === null || (string) $dep->batch_id !== (string) $batch->id) {
                continue;
            }
            if (in_array($dep->state, [JobState::Pending, JobState::Queued, JobState::Retrying], true)) {
                $this->cancelMember($dep, self::UNREACHABLE_REASON);
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

    /**
     * Undo the unreachable-cascade for the direct dependents of a member that
     * re-entered the DAG. Only members the system itself canceled (matched by
     * cancel_reason) revive, and only once NO doomed upstream remains — a
     * dependent behind a second, still-failed upstream stays canceled until
     * that one is retried too (reviving it early would just see the stranded
     * sweep cancel it again).
     */
    private function reviveUnreachableDependents(mixed $upstreamId, Batch $batch): void
    {
        $dependentIds = $this->connection()->table($this->tbl('job_dependencies'))
            ->where('depends_on_job_id', $upstreamId)
            ->where('edge_condition', '!=', 'on_completion')
            ->pluck('job_id');

        foreach ($dependentIds as $id) {
            $dep = Job::find($id);
            if ($dep === null || (string) $dep->batch_id !== (string) $batch->id) {
                continue;
            }
            if ($dep->state !== JobState::Canceled || (string) $dep->cancel_reason !== self::UNREACHABLE_REASON) {
                continue;
            }
            if ($this->hasDoomedDependency($dep->id)) {
                continue;
            }
            $this->reviveMember($dep);
        }
    }

    /** Revive a canceled-as-unreachable member back to waiting on its dependencies. */
    private function reviveMember(Job $job): void
    {
        try {
            // One transaction: the cancellation-flag withdrawal and the audited
            // state move commit together (mirrors OperatorActions::requeue).
            $this->connection()->transaction(function () use ($job): void {
                $conn = $this->connection();

                // The decision inputs may be stale by now (an operator can have
                // canceled the batch, or re-canceled the member with their own
                // verdict, since the caller selected it) — re-verify them here.
                // The batch row is locked so a concurrent batch-cancel
                // serializes against this revival instead of interleaving; a
                // canceler always takes the batch row before the member rows,
                // and so does this (via the counter update in the transition).
                $batchState = $conn->table($this->tbl('batches'))
                    ->where('id', $job->batch_id)
                    ->lockForUpdate()
                    ->value('state');
                if ($batchState === null || ! self::acceptsRevival(BatchState::from($batchState))) {
                    return;
                }

                $affected = $conn->table($this->tbl('jobs'))
                    ->where('id', $job->id)
                    ->where('state', JobState::Canceled->value)
                    ->where('cancel_reason', self::UNREACHABLE_REASON)
                    ->update([
                        'cancel_requested' => false,
                        'cancel_mode' => null,
                        'cancel_reason' => null,
                        'cancel_requested_at' => null,
                        'finished_at' => null,
                        'updated_at' => $conn->raw(SqlTime::nowExpr($conn)),
                    ]);
                if ($affected === 0) {
                    return; // no longer the system's own cascade-cancel
                }
                $job->refresh();

                $this->stateMachine->applyJobTransition(
                    $job,
                    JobState::Pending,
                    TransitionContext::for(ActorType::System, null, 'revived: upstream dependency was re-queued')
                );
            });
        } catch (IllegalTransitionException|GuardFailedException|StaleFencingTokenException) {
            // raced with another transition — leave the member as it is.
        }
    }

    private function hasDoomedDependency(mixed $jobId): bool
    {
        return $this->connection()->table($this->tbl('job_dependencies').' as d')
            ->join($this->tbl('jobs').' as dep', 'dep.id', '=', 'd.depends_on_job_id')
            ->where('d.job_id', $jobId)
            ->where('d.edge_condition', '!=', 'on_completion')
            ->whereIn('dep.state', array_map(static fn (JobState $s): string => $s->value, self::DOOMED))
            ->exists();
    }

    /**
     * Reopen a completed batch whose member re-entered the DAG. Only the
     * derived verdicts (partial, failed) reopen — canceled/stopped are operator
     * verdicts on the whole batch and stay put. A failure policy that would
     * still trip eagerly (fail_fast/threshold with enough failures on record)
     * keeps the batch failed: reopening would only see the next sweep re-fail
     * it and cancel the retried member again.
     */
    private function reopenBatch(Batch $batch): bool
    {
        $batch->refresh();
        $from = $batch->state;
        if (! in_array($from, [BatchState::Partial, BatchState::Failed], true) || $this->shouldEagerFail($batch)) {
            return false;
        }

        $conn = $this->connection();
        $now = $conn->raw(SqlTime::nowExpr($conn));
        $affected = $conn->table($this->tbl('batches'))
            ->where('id', $batch->id)
            ->where('state', $from->value)
            ->update([
                'state' => BatchState::Running->value,
                'summary' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            $batch->refresh(); // lost the race — let the caller see the truth

            return false;
        }

        // Withdraw the eager-fail sweep's cancellation from members it only
        // FLAGGED (they were running, so cancelMember left the desired-state
        // flag for their supervisor): the verdict that armed those flags no
        // longer stands, and honoring one now would kill a healthy member of
        // the reopened batch. Matched by the sweep's own reason so an
        // operator's cancel flag is never withdrawn.
        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];
        $conn->table($this->tbl('jobs'))
            ->where('batch_id', $batch->id)
            ->whereNotIn('state', $terminal)
            ->where('cancel_requested', true)
            ->where('cancel_reason', "batch member failed under {$batch->failure_policy}")
            ->update([
                'cancel_requested' => false,
                'cancel_mode' => null,
                'cancel_reason' => null,
                'cancel_requested_at' => null,
                'updated_at' => $now,
            ]);

        $batch->state = BatchState::Running;
        $batch->summary = null;
        $batch->finished_at = null;
        event(new BatchStateChanged($batch, $from, BatchState::Running, 'member re-entered the DAG'));

        return true;
    }

    /**
     * Terminal batches a lost re-entry event left inconsistent: partial/failed
     * with members back in flight. Both counters were 0 at completion, the
     * pending bucket (pending/queued/retrying) is only regained through a
     * retry/restart/revival, and the running bucket only regrows out of queued
     * — so any in-flight member of a completed batch implies a re-entry, even
     * one already claimed to running before this sweep got to it.
     *
     * Batches whose failure policy is STILL tripped are excluded: reopenBatch
     * refuses them anyway, and under an eager policy in-flight work on a failed
     * batch is now also the NORMAL state of affairs (a spared finalizer, or a
     * flagged member whose supervisor hasn't caught up). Without this the window
     * would refill with the same unreopenable ids every tick — and a finalizer
     * blocked behind an orphan would hold its slot indefinitely.
     *
     * @return string[]
     */
    private function reopenableBatchIds(int $limit): array
    {
        return $this->connection()->table($this->tbl('batches'))
            ->whereIn('state', [BatchState::Partial->value, BatchState::Failed->value])
            ->whereRaw('pending_count + running_count > 0')
            ->whereNot(function ($q): void {   // the SQL twin of shouldEagerFail()
                $q->where(function ($q): void {
                    $q->where('failure_policy', 'fail_fast')->where('failed_count', '>', 0);
                })->orWhere(function ($q): void {
                    $q->where('failure_policy', 'threshold')
                        ->whereRaw('failed_count > COALESCE(failure_threshold, 0)');
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Members of live batches still canceled by the unreachable-cascade even
     * though no doomed upstream remains — the revival event that should have
     * restored them was lost. The inverse of strandedMemberIds(), and its batch
     * states must match it exactly (see acceptsRevival).
     *
     * @return string[]
     */
    private function revivableMemberIds(int $limit): array
    {
        $doomed = array_map(static fn (JobState $s): string => $s->value, self::DOOMED);

        return $this->connection()->table($this->tbl('jobs').' as j')
            ->join($this->tbl('batches').' as b', 'b.id', '=', 'j.batch_id')
            ->whereIn('b.state', [BatchState::Running->value, BatchState::Failed->value])
            ->where('j.state', JobState::Canceled->value)
            ->where('j.cancel_reason', self::UNREACHABLE_REASON)
            ->whereNotExists(function ($q) use ($doomed): void {
                $q->selectRaw('1')
                    ->from($this->tbl('job_dependencies').' as d')
                    ->join($this->tbl('jobs').' as dep', 'dep.id', '=', 'd.depends_on_job_id')
                    ->whereColumn('d.job_id', 'j.id')
                    ->where('d.edge_condition', '!=', 'on_completion')
                    ->whereIn('dep.state', $doomed);
            })
            ->orderBy('j.id')
            ->limit($limit)
            ->pluck('j.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Running batches whose lost member-event is re-derivable from the counters:
     * nothing left in flight (a lost completion), or an eager failure policy that
     * a member's recorded failure should already have tripped.
     *
     * @return string[]
     */
    private function reconcilableBatchIds(int $limit): array
    {
        return $this->connection()->table($this->tbl('batches'))
            ->where('state', BatchState::Running->value)
            ->where(function ($q): void {
                $q->whereRaw('pending_count + running_count = 0')
                    ->orWhere(function ($q): void {
                        $q->where('failure_policy', 'fail_fast')->where('failed_count', '>', 0);
                    })
                    ->orWhere(function ($q): void {
                        $q->where('failure_policy', 'threshold')
                            ->whereRaw('failed_count > COALESCE(failure_threshold, 0)');
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Pending members of live batches with an on_success upstream that is
     * terminally non-succeeded — they can never be admitted and a lost cascade
     * event means nobody canceled them. `orphaned` upstreams are deliberately
     * NOT doomed: a parked orphan awaits an operator verdict and may still be
     * restarted. A member whose only doomed upstreams are on_completion ones is
     * not stranded at all — it is admissible right now.
     *
     * @return string[]
     */
    private function strandedMemberIds(int $limit): array
    {
        $doomed = array_map(static fn (JobState $s): string => $s->value, self::DOOMED);

        return $this->connection()->table($this->tbl('jobs').' as j')
            ->join($this->tbl('batches').' as b', 'b.id', '=', 'j.batch_id')
            // `failed` too, not just `running`: an eagerly-failed batch keeps its
            // spared finalizer subtree in flight, and a lost event inside that
            // subtree strands a dependent exactly as it would in a live batch.
            ->whereIn('b.state', [BatchState::Running->value, BatchState::Failed->value])
            ->where('j.state', JobState::Pending->value)
            ->whereExists(function ($q) use ($doomed): void {
                $q->selectRaw('1')
                    ->from($this->tbl('job_dependencies').' as d')
                    ->join($this->tbl('jobs').' as dep', 'dep.id', '=', 'd.depends_on_job_id')
                    ->whereColumn('d.job_id', 'j.id')
                    ->where('d.edge_condition', '!=', 'on_completion')
                    ->whereIn('dep.state', $doomed);
            })
            ->orderBy('j.id')
            ->limit($limit)
            ->pluck('j.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
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
