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

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled, self::Stopped => true,
            default => false,
        };
    }
}
