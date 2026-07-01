<?php

declare(strict_types=1);

namespace JobWarden\Recovery;

use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Decides a Job's fate after its current attempt fails or is orphaned, gated by
 * the SINGLE binary guard — idempotency (spec §3.4). Idempotent → retry (a fresh
 * attempt). Non-idempotent → indeterminate, so park for an operator (or auto-fail
 * per config). There is no middle category. Operator cancel/stop desired-state is
 * honored ahead of retry.
 */
class RecoveryService
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    /** The current (running) attempt failed; retry within budget if idempotent, else fail. */
    public function afterAttemptFailure(Job $job, ActorType $actor, ?string $reason = null): void
    {
        $job->refresh();
        $reason ??= 'attempt failed';

        // Cancellation desired-state wins over retry (spec §6.3).
        if ($this->honorCancellation($job, $actor)) {
            return;
        }

        if ($this->canRetry($job)) {
            $this->scheduleRetry($job, JobState::Running, $actor, $reason);

            return;
        }

        $this->stateMachine->applyJobTransition($job, JobState::Failed, TransitionContext::for($actor, null, $reason));
    }

    /**
     * A job's current attempt was orphaned (lost host / dead supervisor). Per the
     * idempotency guard: idempotent jobs retry (a fresh attempt is minted on the
     * next claim, possibly on ANOTHER host); non-idempotent jobs PARK in orphaned
     * for an operator (default) or auto-fail, per config (spec §3.4 / §5.4 / §13.3).
     * An operator's cancel/stop desired-state is honored ahead of retry.
     */
    public function resolveOrphan(Job $job, ActorType $actor, ?string $reason = null): void
    {
        $job->refresh();

        if ($job->state !== JobState::Orphaned) {
            return; // someone already resolved it
        }

        $reason ??= 'attempt orphaned';

        // 1. Cancellation desired-state is honored on recovery (spec §6.3).
        if ($this->honorCancellation($job, $actor)) {
            return;
        }

        // 2. Idempotent with budget left → retry (a fresh attempt, possibly on another host).
        if ($this->canRetry($job)) {
            $this->scheduleRetry($job, JobState::Orphaned, $actor, $reason);

            return;
        }

        // 3. Idempotent but budget EXHAUSTED → fail. Spec §3.6: `orphaned → failed`
        //    when "idempotent = false OR budget exhausted". An idempotent job is
        //    never indeterminate, so it must not park in `orphaned` limbo just
        //    because a reaper (not the worker) delivered the final lost attempt —
        //    the determinate verdict is `failed`, exactly as afterAttemptFailure()
        //    resolves an exhausted budget. Parking is only for the genuinely
        //    non-idempotent case below.
        if ((bool) $job->idempotent) {
            $this->stateMachine->applyJobTransition($job, JobState::Failed, TransitionContext::for($actor, null, $reason.' (attempts exhausted)'));

            return;
        }

        // 4. Non-idempotent (the binary guard, spec §3.4): the lost attempt is
        //    indeterminate, so park (default) or auto-fail, per config. The Job
        //    never auto-resurrects this attempt — recovery is always a fresh
        //    attempt (idempotent) or an operator decision.
        $policy = (string) config('jobwarden.retry.non_idempotent_orphan_policy', 'park');
        if ($policy === 'auto_fail') {
            $this->stateMachine->applyJobTransition($job, JobState::Failed, TransitionContext::for($actor, null, $reason.' (non-idempotent: auto-fail)'));
        }
        // 'park': leave the job in `orphaned` for an explicit, audited operator restart.
    }

    /** Honor an operator's cancel/stop desired-state when there is no live owner. */
    private function honorCancellation(Job $job, ActorType $actor): bool
    {
        if (! $job->cancel_requested) {
            return false;
        }

        try {
            $this->stateMachine->applyJobTransition(
                $job,
                JobState::Stopped,
                TransitionContext::for($actor, null, 'cancellation honored on recovery: '.((string) $job->cancel_reason))
            );

            return true;
        } catch (IllegalTransitionException|StaleFencingTokenException) {
            return false;
        }
    }

    public function canRetry(Job $job): bool
    {
        return (bool) $job->idempotent && (int) $job->attempt_count < (int) $job->max_attempts;
    }

    /** Move a job to `retrying` with a backoff-delayed available_at (P5/P8 shared). */
    public function scheduleRetry(Job $job, JobState $from, ActorType $actor, string $reason): void
    {
        $backoff = Backoff::fromConfig((int) $job->attempt_count, $job->backoff_strategy);
        $this->setAvailableAt($job, Carbon::now()->addSeconds($backoff));

        $this->stateMachine->applyJobTransition(
            $job,
            JobState::Retrying,
            TransitionContext::for($actor, null, $reason)->withContext(['backoff_sec' => $backoff])
        );
    }

    private function setAvailableAt(Job $job, Carbon $when): void
    {
        DB::connection(config('jobwarden.connection'))
            ->table(((string) config('jobwarden.table_prefix')).'jobs')
            ->where('id', $job->id)
            ->update(['available_at' => $when, 'updated_at' => Carbon::now()]);

        $job->available_at = $when; // keep the in-memory model honest (not a state change)
    }
}
