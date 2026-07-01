<?php

declare(strict_types=1);

namespace JobWarden\Events;

use JobWarden\Models\JobAttempt;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\AttemptState;

/**
 * Dispatched AFTER commit (spec §11.3).
 */
final class AttemptStateChanged
{
    public function __construct(
        public readonly JobAttempt $attempt,
        public readonly AttemptState $from,
        public readonly AttemptState $to,
        public readonly TransitionContext $context,
        public readonly int $eventId,
    ) {
    }
}
