<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Models\JobAttempt;
use JobWarden\Runner\JobContext;
use RuntimeException;

/**
 * Parameter-driven handler for result-storage tests. Stores a completion
 * payload via $context->result(), then optionally misbehaves:
 *   result     — the payload to store (default ['ok' => true])
 *   fill_bytes — pad the payload with a string this long (size-cap tests)
 *   then_fail  — throw AFTER setting the result (it must not persist)
 *   fence_out  — bump the attempt's fencing token after setting the result,
 *                simulating a reaper takeover while the child finishes
 */
final class ResultJob implements JobWardenJob
{
    public function __construct(
        private readonly array $result = ['ok' => true],
        private readonly int $fill_bytes = 0,
        private readonly bool $then_fail = false,
        private readonly bool $fence_out = false,
    ) {
    }

    public function handle(JobContext $context): void
    {
        $payload = $this->result;
        if ($this->fill_bytes > 0) {
            $payload['fill'] = str_repeat('x', $this->fill_bytes);
        }

        $context->result($payload);

        if ($this->then_fail) {
            throw new RuntimeException('failed after setting a result');
        }

        if ($this->fence_out) {
            JobAttempt::query()->whereKey($context->attemptId)->increment('fencing_token');
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
