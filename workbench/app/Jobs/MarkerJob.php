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
    public function __construct(
        private readonly int $sleep = 0,
        private readonly string $marker = '',
    ) {
    }

    public function handle(JobContext $context): void
    {
        if ($this->sleep > 0) {
            sleep($this->sleep);
        }

        if ($this->marker !== '') {
            file_put_contents($this->marker, 'done:'.$context->attemptId);
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
