<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * Reports what the runner handed it about its batch — the handler-side view a
 * finalizer (a member joined by a dependsOnCompletion edge) reacts to. Stores it
 * as the completion payload so a test can read it back off the job row.
 */
final class BatchAwareJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $context->result([
            'batch_id' => $context->batchId,
            'batch' => $context->batch(),
        ]);
    }

    public function idempotent(): bool
    {
        return true;
    }
}
