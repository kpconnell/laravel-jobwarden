<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use RuntimeException;

/** Throws — exercises the determinate failure path (child reports failed). */
final class FailingJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        throw new RuntimeException((string) ($context->params['message'] ?? 'boom'));
    }

    public function idempotent(): bool
    {
        return false;
    }
}
