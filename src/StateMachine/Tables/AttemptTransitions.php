<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Tables;

use JobWarden\StateMachine\Contracts\TransitionTable;
use JobWarden\StateMachine\Transition;
use JobWarden\States\ActorType as A;
use JobWarden\States\AttemptState as S;

/**
 * The Attempt machine, encoded literally from spec §3.2 / §3.6.
 *
 * Worker/supervisor reports of a determinate outcome require the current
 * fencing token (requiresFencingToken=true): a stale epoch is rejected by the
 * guarded UPDATE. Reaper orphan edges bump the epoch instead. `orphaned →
 * running` (re-adopt) is deliberately ABSENT — deferred to post-v1 (§0.1/§3.2).
 */
final class AttemptTransitions implements TransitionTable
{
    /** @var array<string, array<string, Transition>> */
    private array $map;

    public function __construct()
    {
        $defs = [
            new Transition(S::Dispatched, S::Running, [A::Worker, A::Supervisor], requiresFencingToken: true),
            new Transition(S::Dispatched, S::Orphaned, [A::Reaper, A::System]),
            new Transition(S::Dispatched, S::Canceled, [A::Operator, A::Supervisor]),
            // A child that dies before it ever reports `running` (boot crash,
            // OOM, stale-token refusal) is forced terminal by the supervisor.
            new Transition(S::Dispatched, S::Failed, [A::Supervisor, A::System]),
            new Transition(S::Dispatched, S::Stopped, [A::Supervisor, A::Operator]),

            new Transition(S::Running, S::Succeeded, [A::Worker, A::Supervisor], requiresFencingToken: true),
            new Transition(S::Running, S::Failed, [A::Worker, A::Supervisor], requiresFencingToken: true),
            new Transition(S::Running, S::Orphaned, [A::Reaper, A::System]),
            new Transition(S::Running, S::Stopped, [A::Operator, A::Supervisor]),
        ];

        $map = [];
        foreach ($defs as $t) {
            $map[$t->from->value][$t->to->value] = $t;
        }
        $this->map = $map;
    }

    public function level(): string
    {
        return 'attempt';
    }

    public function find(string $from, string $to): ?Transition
    {
        return $this->map[$from][$to] ?? null;
    }
}
