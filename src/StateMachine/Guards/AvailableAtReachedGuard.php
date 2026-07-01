<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The job's available_at has been reached (or is unset). Gates admission out of
 * pending/retrying.
 */
final class AvailableAtReachedGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        $availableAt = $entity->getAttribute('available_at');

        if ($availableAt === null) {
            return true;
        }

        return Carbon::parse($availableAt)->lessThanOrEqualTo(Carbon::now());
    }

    public function reason(): string
    {
        return 'available_at has not been reached';
    }
}
