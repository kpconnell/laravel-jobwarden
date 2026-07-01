<?php

declare(strict_types=1);

namespace JobWarden\StateMachine;

final class TransitionResult
{
    public function __construct(
        public readonly \BackedEnum $from,
        public readonly \BackedEnum $to,
        public readonly int $eventId,
        public readonly ?int $fencingToken = null,
    ) {
    }
}
