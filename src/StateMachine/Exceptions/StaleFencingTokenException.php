<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Exceptions;

use RuntimeException;

/**
 * The guarded UPDATE matched zero rows: the entity was no longer in the
 * expected `from` state, or its fencing token had moved on. The write is
 * rejected (never clobbers state) and a reconciliation event is recorded
 * instead (spec §5.3).
 */
final class StaleFencingTokenException extends RuntimeException
{
    public function __construct(
        public readonly string $level,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $expectedFencingToken = null,
    ) {
        $detail = $expectedFencingToken !== null
            ? "expected fencing token {$expectedFencingToken}"
            : "no longer in state {$from}";

        parent::__construct("Stale {$level} write {$from} → {$to} rejected ({$detail})");
    }
}
