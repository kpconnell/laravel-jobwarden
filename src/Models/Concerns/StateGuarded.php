<?php

declare(strict_types=1);

namespace JobWarden\Models\Concerns;

use JobWarden\Exceptions\DirectStateMutationException;

/**
 * Turns "only the StateMachine writes state" from a convention into a runtime
 * invariant. The StateMachine performs its guarded UPDATE via the query builder
 * (bypassing Eloquent model events), so any state change that arrives through a
 * model save/update is by definition NOT the StateMachine — and is rejected.
 *
 * Initial state on INSERT is allowed (the `updating` hook only fires on update).
 */
trait StateGuarded
{
    public static function bootStateGuarded(): void
    {
        static::updating(function ($model): void {
            if ($model->isDirty('state')) {
                throw new DirectStateMutationException(static::class);
            }
        });
    }
}
