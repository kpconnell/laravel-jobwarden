<?php

declare(strict_types=1);

namespace JobWarden\States;

/**
 * One immutable execution attempt bound to a single worker/lease epoch.
 * See architecture spec §3.2. `orphaned` is an indeterminate limbo, not a clean
 * exit: it is abandoned (no outgoing edges in v1 — re-adopt is deferred), and the
 * Job recovers by minting a FRESH attempt, never by resurrecting this one.
 */
enum AttemptState: string
{
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
    case Canceled = 'canceled';
    case Stopped = 'stopped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled, self::Stopped => true,
            default => false,
        };
    }

    /** States in which a stamped process may still be alive on a host. */
    public function isInFlight(): bool
    {
        return $this === self::Dispatched || $this === self::Running;
    }
}
