<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\JobState;
use Illuminate\Database\Eloquent\Model;

/**
 * Cancel (intent withdrawal) is only valid before active execution. Once a job
 * is running the operator must `stop` it instead (spec §3.4).
 */
final class NotYetRunningGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        $state = $entity->getAttribute('state');
        $state = $state instanceof JobState ? $state : JobState::from((string) $state);

        return in_array($state, [JobState::Pending, JobState::Queued, JobState::Retrying], true);
    }

    public function reason(): string
    {
        return 'job is already running; use stop instead of cancel';
    }
}
