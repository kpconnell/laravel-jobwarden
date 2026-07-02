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
    public function handle(JobContext $context): void
    {
        $payload = (array) ($context->params['result'] ?? ['ok' => true]);
        if (($fill = (int) ($context->params['fill_bytes'] ?? 0)) > 0) {
            $payload['fill'] = str_repeat('x', $fill);
        }

        $context->result($payload);

        if (! empty($context->params['then_fail'])) {
            throw new RuntimeException('failed after setting a result');
        }

        if (! empty($context->params['fence_out'])) {
            JobAttempt::query()->whereKey($context->attemptId)->increment('fencing_token');
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
