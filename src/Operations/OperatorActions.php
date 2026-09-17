<?php

declare(strict_types=1);

namespace JobWarden\Operations;

use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Support\Facades\DB;

/**
 * Durable, audited operator actions (spec §10.1). Halting is desired-state in
 * the database (spec §6.3) so it works across hosts: set the flag, apply the
 * immediate transition where there is no live owner, and let the owning
 * supervisor (or recovery) honor it otherwise.
 *
 * Each verb is scoped to the states it names, so a verb maps to one verdict:
 *   cancel   withdraws work that has not started      → canceled
 *   stop     halts work that is active or parked      → stopped
 *   skip     rules the node's outcome irrelevant      → skipped (from anywhere but succeeded)
 *   retry    re-runs a failed job                     → queued
 *   restart  re-runs a stopped or parked job          → queued
 * A verb applied outside its states is refused (InvalidArgumentException),
 * exactly as retry/restart always were — never silently mapped to another verb.
 */
final class OperatorActions
{
    private const PRE_RUN = [JobState::Pending, JobState::Queued, JobState::Retrying];

    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    /**
     * Withdraw work that has not started → canceled. The flag is written first,
     * so if a claim wins the race the owning supervisor still halts the child —
     * that job lands `stopped`, because it WAS running when halted.
     */
    public function cancel(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->assertState($job, self::PRE_RUN, 'cancel');
        $this->halt($job, 'cancel', JobState::Canceled, $reason, $actorId);
    }

    /**
     * Halt active work → stopped. A running job is flagged and signaled by its
     * supervisor; a parked orphan (no live owner) is stopped immediately.
     */
    public function stop(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->assertState($job, [JobState::Running, JobState::Orphaned], 'stop');
        $this->halt($job, 'stop', JobState::Stopped, $reason, $actorId);
    }

    /**
     * Rule the node's outcome irrelevant → skipped: its dependents proceed as
     * if it had succeeded (JobState::satisfiesSuccessEdge). A settled job
     * (failed/stopped/canceled) moves directly. Anything still live takes the
     * desired-state path with cancel_mode = 'skip' — a running job is killed by
     * its supervisor and then lands `skipped` ("kill and skip"); pre-run work
     * and a parked orphan move immediately, the flag covering the claim race.
     */
    public function skip(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->assertState($job, [
            JobState::Failed, JobState::Stopped, JobState::Canceled, JobState::Orphaned,
            JobState::Running, ...self::PRE_RUN,
        ], 'skip');

        if ($job->state->isTerminal()) {
            $this->stateMachine->applyJobTransition($job, JobState::Skipped, TransitionContext::for(ActorType::Operator, $actorId, $reason));

            return;
        }

        $this->halt($job, 'skip', JobState::Skipped, $reason, $actorId);
    }

    /** Operator retry of a FAILED job → re-queue, minting a fresh attempt. */
    public function retry(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->assertState($job, [JobState::Failed], 'retry');
        $this->requeue($job, $reason, $actorId);
    }

    /** Operator restart of a parked ORPHAN or STOPPED job → re-queue. */
    public function restart(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->assertState($job, [JobState::Orphaned, JobState::Stopped], 'restart');
        $this->requeue($job, $reason, $actorId);
    }

    /**
     * Desired-state first: even if the immediate transition loses a race to a
     * claim, the flag remains and the supervisor/recovery honor it. `$to` is
     * the immediate landing state where there is no live owner; a running job
     * is only flagged, and lands per the mode when its supervisor reaps it
     * (JobState::haltedState).
     */
    private function halt(Job $job, string $mode, JobState $to, string $reason, ?string $actorId): void
    {
        $this->setCancelFlags($job, $mode, $reason);
        $job->refresh();

        if ($job->state === JobState::Running) {
            return; // the owning supervisor observes the flag and stops the child.
        }

        try {
            $this->stateMachine->applyJobTransition($job, $to, TransitionContext::for(ActorType::Operator, $actorId, $reason));
        } catch (IllegalTransitionException|GuardFailedException|StaleFencingTokenException) {
            // Raced with a claim/transition — the desired-state flag remains in effect.
        }
    }

    private function requeue(Job $job, string $reason, ?string $actorId): void
    {
        // One transaction: the eligibility resets (available_at, cancellation
        // withdrawal) and the audited state move commit together, so a failed
        // transition never leaves the flags mutated without the re-queue.
        $this->connection()->transaction(function () use ($job, $reason, $actorId): void {
            $this->connection()->table($this->tbl('jobs'))->where('id', $job->id)->update([
                // DB clock (not Carbon::now()) so the re-queued job's eligibility, checked
                // against CURRENT_TIMESTAMP in the claim, is timezone-agnostic.
                'available_at' => $this->connection()->raw('CURRENT_TIMESTAMP'),
                'cancel_requested' => false,
                'cancel_mode' => null,
                'updated_at' => $this->connection()->raw('CURRENT_TIMESTAMP'),
            ]);
            $job->refresh();

            $this->stateMachine->applyJobTransition(
                $job,
                JobState::Queued,
                TransitionContext::for(ActorType::Operator, $actorId, $reason)
            );
        });
    }

    private function setCancelFlags(Job $job, string $mode, string $reason): void
    {
        $conn = $this->connection();
        $now = $conn->raw(SqlTime::nowExpr($conn));
        $conn->table($this->tbl('jobs'))->where('id', $job->id)->update([
            'cancel_requested' => true,
            'cancel_mode' => $mode,
            'cancel_reason' => $reason,
            'cancel_requested_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param list<JobState> $allowed */
    private function assertState(Job $job, array $allowed, string $action): void
    {
        if (in_array($job->state, $allowed, true)) {
            return;
        }

        $states = implode(' or ', array_map(static fn (JobState $state): string => $state->value, $allowed));
        throw new \InvalidArgumentException("Cannot {$action} a {$job->state->value} job; expected {$states}.");
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
