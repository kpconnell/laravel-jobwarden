<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Budget remains: attempt_count < max_attempts.
 */
final class AttemptBudgetGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        return (int) $entity->getAttribute('attempt_count') < (int) $entity->getAttribute('max_attempts');
    }

    public function reason(): string
    {
        return 'attempt budget exhausted';
    }
}
