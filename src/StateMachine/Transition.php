<?php

declare(strict_types=1);

namespace JobWarden\StateMachine;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\States\ActorType;

/**
 * One allowed edge in a transition table: its from/to states, the actors
 * permitted to drive it, the decided-input guards it must satisfy, and whether
 * it requires the caller to supply the current fencing token.
 */
final class Transition
{
    /**
     * @param ActorType[] $allowedActors
     * @param Guard[] $guards
     */
    public function __construct(
        public readonly \BackedEnum $from,
        public readonly \BackedEnum $to,
        public readonly array $allowedActors,
        public readonly array $guards = [],
        public readonly bool $requiresFencingToken = false,
    ) {
    }

    public function allowsActor(ActorType $actor): bool
    {
        return in_array($actor, $this->allowedActors, true);
    }
}
