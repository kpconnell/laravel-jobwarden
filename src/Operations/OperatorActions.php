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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Durable, audited operator actions (spec §10.1). Cancellation is desired-state
 * in the database (spec §6.3) so it works across hosts: set the flag, apply the
 * immediate transition where there is no live owner, and let the owning
 * supervisor (or recovery) honor it otherwise.
 */
final class OperatorActions
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    /** Withdraw a job. Pre-run → canceled immediately; running → flagged for the supervisor. */
    public function cancel(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->halt($job, 'cancel', $reason, $actorId);
    }

    /** Halt active work. Running → flagged (supervisor stops it); else handled like cancel. */
    public function stop(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->halt($job, 'stop', $reason, $actorId);
    }

    /** Operator retry of a FAILED job → re-queue, minting a fresh attempt. */
    public function retry(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->requeue($job, $reason, $actorId);
    }

    /** Operator restart of a parked ORPHAN → re-queue (override, even non-idempotent). */
    public function restart(Job $job, string $reason, ?string $actorId = null): void
    {
        $this->requeue($job, $reason, $actorId);
    }

    private function halt(Job $job, string $mode, string $reason, ?string $actorId): void
    {
        // Desired-state first: even if the immediate transition loses a race to a
        // claim, the flag remains and the supervisor/recovery will honor it.
        $this->setCancelFlags($job, $mode, $reason);
        $job->refresh();

        $context = TransitionContext::for(ActorType::Operator, $actorId, $reason);

        try {
            match (true) {
                in_array($job->state, [JobState::Pending, JobState::Queued, JobState::Retrying], true)
                    => $this->stateMachine->applyJobTransition($job, JobState::Canceled, $context),
                $job->state === JobState::Orphaned
                    => $this->stateMachine->applyJobTransition($job, JobState::Stopped, $context),
                // running/dispatched: the owning supervisor observes the flag and stops the child.
                default => null,
            };
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
                'available_at' => Carbon::now(),
                'cancel_requested' => false,
                'cancel_mode' => null,
                'updated_at' => Carbon::now(),
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
        $this->connection()->table($this->tbl('jobs'))->where('id', $job->id)->update([
            'cancel_requested' => true,
            'cancel_mode' => $mode,
            'cancel_reason' => $reason,
            'cancel_requested_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
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
