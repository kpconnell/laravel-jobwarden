<?php

declare(strict_types=1);

namespace JobWarden\States;

/**
 * The Job (Run) lifecycle — the durable intent and verdict that outlive any
 * single execution. See architecture spec §3.3.
 */
enum JobState: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Retrying = 'retrying';
    case Orphaned = 'orphaned';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Stopped = 'stopped';

    /**
     * An operator's verdict that this node's outcome does not matter to the
     * graph: dependents proceed as if it had succeeded. Never produced by the
     * system. The job keeps its last_error and attempt history; the reason is
     * on the job_events row like any other operator action.
     */
    case Skipped = 'skipped';

    /**
     * Where a job that was halted while ACTIVE lands, given the desired-state
     * flag's mode. `skip` is the one mode whose value picks the state — the
     * operator's verdict on the graph — while cancel and stop both describe how
     * the run ended: halted while active, so `stopped`. (A pre-run job with a
     * cancel flag never reaches here; it is `canceled` directly.)
     */
    public static function haltedState(string $cancelMode): self
    {
        return $cancelMode === 'skip' ? self::Skipped : self::Stopped;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled, self::Stopped, self::Skipped => true,
            default => false,
        };
    }

    /**
     * Does this state satisfy an `on_success` dependency edge? `succeeded` by
     * definition; `skipped` because that is precisely what the verdict means.
     */
    public function satisfiesSuccessEdge(): bool
    {
        return $this === self::Succeeded || $this === self::Skipped;
    }

    /**
     * The terminal states, as column values. Every SQL predicate over "has this
     * job ended" (dependency guards, the admit window, retention, overlap) reads
     * this list rather than spelling it out, so a new terminal state cannot be
     * missed by one of them.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isTerminal()),
        ));
    }

    /**
     * The states that satisfy an `on_success` edge, as column values — the SQL
     * twin of satisfiesSuccessEdge(). Same rule as terminalValues(): every
     * admission predicate reads this list.
     *
     * @return list<string>
     */
    public static function satisfyingValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->satisfiesSuccessEdge()),
        ));
    }
}
