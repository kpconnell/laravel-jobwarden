<?php

declare(strict_types=1);

namespace JobWarden\States;

/**
 * Batch lifecycle. Completion is derived from member-job terminal states under
 * the batch's failure policy. See architecture spec §3.5 / §7.
 */
enum BatchState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';
    case Canceled = 'canceled';
    case Stopped = 'stopped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Partial, self::Canceled, self::Stopped => true,
            default => false,
        };
    }
}
