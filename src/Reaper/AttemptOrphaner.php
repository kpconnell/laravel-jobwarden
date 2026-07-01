<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Logging\JobLogger;
use JobWarden\Models\JobAttempt;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;

/**
 * The one audited path both reapers use to orphan an attempt: bump the fencing
 * token (attempt → orphaned), inject a log entry into the job's own log
 * explaining the action, move the job → orphaned, and run recovery.
 */
final class AttemptOrphaner
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly RecoveryService $recovery,
        private readonly JobLogger $jobLogger,
    ) {
    }

    public function orphan(JobAttempt $attempt, string $reaperId, string $hostId, string $tier, string $reason): bool
    {
        $context = TransitionContext::for(ActorType::Reaper, $reaperId, $reason)
            ->bumpingFence()
            ->withProcessSnapshot(['host_id' => $hostId, 'tier' => $tier]);

        try {
            // ONE transaction: fence the attempt, log the action, move the job, and
            // run recovery together. A reaper dying mid-orphan can no longer strand
            // `attempt=orphaned, job=running` — either the whole aggregate moves or
            // none of it does (and the next scan retries cleanly).
            $this->connection()->transaction(function () use ($attempt, $context, $reaperId, $hostId, $tier, $reason): void {
                $this->stateMachine->applyAttemptTransition($attempt, AttemptState::Orphaned, $context);

                $this->jobLogger->write(
                    (string) $attempt->job_id,
                    (string) $attempt->id,
                    'warning',
                    "{$tier} reaper: {$reason}; attempt orphaned and fenced",
                    ['actor' => "{$tier}_reaper", 'reaper_id' => $reaperId, 'host_id' => $hostId, 'tier' => $tier],
                    'reaped',
                );

                $job = $attempt->job;
                if ($job !== null) {
                    if ($job->state === JobState::Running) {
                        $this->stateMachine->applyJobTransition($job, JobState::Orphaned, TransitionContext::for(ActorType::Reaper, $reaperId, $reason));
                    }
                    $this->recovery->resolveOrphan($job->refresh(), ActorType::Reaper, $reason);
                }
            });
        } catch (IllegalTransitionException|StaleFencingTokenException) {
            return false; // already terminal / handled by another tier — retried next scan
        }

        return true;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return \Illuminate\Support\Facades\DB::connection(config('jobwarden.connection'));
    }
}
