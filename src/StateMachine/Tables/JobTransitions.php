<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Tables;

use JobWarden\StateMachine\Contracts\TransitionTable;
use JobWarden\StateMachine\Guards\AttemptBudgetGuard;
use JobWarden\StateMachine\Guards\AvailableAtReachedGuard;
use JobWarden\StateMachine\Guards\DepsSatisfiedGuard;
use JobWarden\StateMachine\Guards\IdempotentGuard;
use JobWarden\StateMachine\Guards\NotYetRunningGuard;
use JobWarden\StateMachine\Transition;
use JobWarden\States\ActorType as A;
use JobWarden\States\JobState as S;

/**
 * The Job (Run) machine, encoded literally from spec §3.3 / §3.6.
 */
final class JobTransitions implements TransitionTable
{
    /** @var array<string, array<string, Transition>> */
    private array $map;

    public function __construct()
    {
        $defs = [
            // admit
            new Transition(S::Pending, S::Queued, [A::Scheduler, A::System], [new AvailableAtReachedGuard, new DepsSatisfiedGuard]),
            new Transition(S::Pending, S::Canceled, [A::Operator, A::System], [new NotYetRunningGuard]),

            // dispatch / run
            new Transition(S::Queued, S::Running, [A::Worker, A::Supervisor]),
            new Transition(S::Queued, S::Canceled, [A::Operator, A::System], [new NotYetRunningGuard]),

            // running outcomes. System is permitted on `succeeded` for the
            // reconciliation sweep: an attempt that already succeeded but whose
            // job was left `running` (a process died in the window between the two
            // transitions) is completed by the leader reaper acting as System.
            new Transition(S::Running, S::Succeeded, [A::Worker, A::Supervisor, A::System]),
            new Transition(S::Running, S::Failed, [A::Worker, A::Supervisor, A::Reaper, A::System]),
            new Transition(S::Running, S::Retrying, [A::Worker, A::Supervisor, A::Reaper, A::System], [new IdempotentGuard, new AttemptBudgetGuard]),
            new Transition(S::Running, S::Orphaned, [A::Reaper, A::System]),
            new Transition(S::Running, S::Stopped, [A::Operator, A::Supervisor, A::Worker, A::System, A::Reaper]),

            // orphan recovery, gated by the binary idempotency guard (spec §3.4)
            new Transition(S::Orphaned, S::Retrying, [A::Reaper, A::System, A::Operator], [new IdempotentGuard, new AttemptBudgetGuard]),
            new Transition(S::Orphaned, S::Failed, [A::Reaper, A::System, A::Operator]),
            // cancellation honored on recovery (no live owner) — spec §6.3
            new Transition(S::Orphaned, S::Stopped, [A::Operator, A::Reaper, A::System]),
            new Transition(S::Orphaned, S::Canceled, [A::Operator, A::Reaper, A::System]),

            // backoff elapsed
            new Transition(S::Retrying, S::Queued, [A::System, A::Scheduler, A::Supervisor], [new AvailableAtReachedGuard]),
            new Transition(S::Retrying, S::Canceled, [A::Operator, A::System], [new NotYetRunningGuard]),

            // operator overrides (audited) — spec §10.1
            new Transition(S::Orphaned, S::Queued, [A::Operator]),  // restart a parked orphan
            new Transition(S::Stopped, S::Queued, [A::Operator]),   // restart stopped work
            new Transition(S::Failed, S::Queued, [A::Operator]),    // retry a failed job
        ];

        $map = [];
        foreach ($defs as $t) {
            $map[$t->from->value][$t->to->value] = $t;
        }
        $this->map = $map;
    }

    public function level(): string
    {
        return 'job';
    }

    public function find(string $from, string $to): ?Transition
    {
        return $this->map[$from][$to] ?? null;
    }
}
