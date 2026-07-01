<?php

declare(strict_types=1);

namespace JobWarden\Claim\Contracts;

use JobWarden\Claim\Claimed;
use JobWarden\Claim\WorkerContext;

interface ClaimDriver
{
    /**
     * Atomically claim up to $want eligible jobs, minting a dispatched attempt
     * (phase-1 stamp) and moving each job queued → running in one transaction.
     *
     * @return Claimed[]
     */
    public function claim(WorkerContext $worker, int $want): array;

    public function name(): string;
}
