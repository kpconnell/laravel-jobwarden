<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Contracts;

use JobWarden\StateMachine\TransitionContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A DECIDED-INPUT guard: a pure precondition evaluated in PHP before the
 * mutation (idempotency, attempt budget, deps satisfied, available_at reached).
 *
 * Concurrency-sensitive guards (fencing token, "still in `from` state") are NOT
 * modeled here — they are enforced atomically in the guarded UPDATE's WHERE
 * clause, never read-then-write.
 */
interface Guard
{
    public function passes(Model $entity, TransitionContext $context): bool;

    /** Human-readable reason recorded when the guard fails. */
    public function reason(): string;
}
