<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use Illuminate\Database\Eloquent\Model;

/**
 * THE guard (spec §3.4): automatic restart is permitted only when the job is
 * marked idempotent.
 */
final class IdempotentGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        return (bool) $entity->getAttribute('idempotent');
    }

    public function reason(): string
    {
        return 'job is not idempotent';
    }
}
