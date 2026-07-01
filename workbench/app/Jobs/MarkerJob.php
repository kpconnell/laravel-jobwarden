<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * Succeeds: optionally sleeps, then writes a marker file. Proves the success
 * path end-to-end (the handler actually ran in the child).
 */
final class MarkerJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $sleep = (int) ($context->params['sleep'] ?? 0);
        if ($sleep > 0) {
            sleep($sleep);
        }

        if (! empty($context->params['marker'])) {
            file_put_contents((string) $context->params['marker'], 'done:'.$context->attemptId);
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
