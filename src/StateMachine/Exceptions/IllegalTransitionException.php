<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Exceptions;

use RuntimeException;

/**
 * The requested edge does not exist in the transition table, or the acting
 * actor is not permitted to drive it. Illegal transitions raise and are never
 * silently applied (spec §3.6 / §11).
 */
final class IllegalTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $level,
        public readonly string $from,
        public readonly string $to,
        ?string $detail = null,
    ) {
        $message = "Illegal {$level} transition {$from} → {$to}";
        if ($detail !== null) {
            $message .= " ({$detail})";
        }

        parent::__construct($message);
    }
}
