<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Exceptions;

use RuntimeException;

/**
 * A decided-input guard (idempotency, attempt budget, deps, available_at)
 * rejected an otherwise-legal edge.
 */
final class GuardFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $guardReason,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Guard failed for {$from} → {$to}: {$guardReason}");
    }
}
