<?php

declare(strict_types=1);

namespace JobWarden\Events;

use JobWarden\Models\Batch;
use JobWarden\States\BatchState;

final class BatchStateChanged
{
    public function __construct(
        public readonly Batch $batch,
        public readonly BatchState $from,
        public readonly BatchState $to,
        public readonly ?string $reason = null,
    ) {
    }
}
