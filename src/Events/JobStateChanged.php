<?php

declare(strict_types=1);

namespace JobWarden\Events;

use JobWarden\Models\Job;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\JobState;

/**
 * Dispatched AFTER the transaction commits (spec §11.3) so listeners never see a
 * state that later rolls back.
 */
final class JobStateChanged
{
    public function __construct(
        public readonly Job $job,
        public readonly JobState $from,
        public readonly JobState $to,
        public readonly TransitionContext $context,
        public readonly int $eventId,
    ) {
    }
}
