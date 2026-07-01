<?php

declare(strict_types=1);

namespace JobWarden\Recovery;

use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use Illuminate\Support\Carbon;

/**
 * The admit pass (spec §5.4 recovery / §3.3): promotes jobs that have become
 * eligible into `queued` so a worker can claim them — `retrying → queued` once
 * the backoff elapses, and `pending → queued` once deps + available_at are met.
 * Folded into the supervisor's pre-claim step.
 */
final class Admitter
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function admit(int $limit = 200): int
    {
        return $this->promote(JobState::Retrying, $limit) + $this->promote(JobState::Pending, $limit);
    }

    private function promote(JobState $from, int $limit): int
    {
        $now = Carbon::now();

        $jobs = Job::query()
            ->where('state', $from->value)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        $promoted = 0;
        foreach ($jobs as $job) {
            try {
                $this->stateMachine->applyJobTransition(
                    $job,
                    JobState::Queued,
                    TransitionContext::for(ActorType::System, null, $from === JobState::Retrying ? 'backoff elapsed' : 'admitted')
                );
                $promoted++;
            } catch (GuardFailedException|StaleFencingTokenException) {
                // deps not yet satisfied, or another worker beat us — skip.
            }
        }

        return $promoted;
    }
}
