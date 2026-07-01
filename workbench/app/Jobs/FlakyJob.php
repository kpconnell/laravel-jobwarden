<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use RuntimeException;

/**
 * Idempotent and flaky: fails the first `fail_until` runs (tracked in a counter
 * file), then succeeds. Exercises idempotent retry + backoff + the admit pass
 * across multiple attempts.
 */
final class FlakyJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $counterFile = (string) ($context->params['counter'] ?? '');
        $failUntil = (int) ($context->params['fail_until'] ?? 1);

        $count = $counterFile !== '' && is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
        $count++;
        if ($counterFile !== '') {
            file_put_contents($counterFile, (string) $count);
        }

        if ($count <= $failUntil) {
            throw new RuntimeException("flaky failure {$count}/{$failUntil}");
        }

        if (! empty($context->params['marker'])) {
            file_put_contents((string) $context->params['marker'], "succeeded after {$count}");
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
