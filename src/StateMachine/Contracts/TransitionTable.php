<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Contracts;

use JobWarden\StateMachine\Transition;

interface TransitionTable
{
    /** 'job' | 'attempt' — the entity level this table governs. */
    public function level(): string;

    /** The allowed transition for from→to, or null if the edge is illegal. */
    public function find(string $from, string $to): ?Transition;
}
