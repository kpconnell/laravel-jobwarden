<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use RuntimeException;

/**
 * Idempotent and flaky: fails the first `fail_until` runs (tracked in a counter
 * file), then succeeds. Exercises idempotent retry + backoff + the admit pass
 * across multiple attempts. Parameter names mirror the params keys verbatim
 * (binding is by exact name), hence the snake_case.
 */
final class FlakyJob implements JobWardenJob
{
    public function __construct(
        private readonly string $counter = '',
        private readonly int $fail_until = 1,
        private readonly string $marker = '',
    ) {
    }

    public function handle(JobContext $context): void
    {
        $count = $this->counter !== '' && is_file($this->counter) ? (int) file_get_contents($this->counter) : 0;
        $count++;
        if ($this->counter !== '') {
            file_put_contents($this->counter, (string) $count);
        }

        if ($count <= $this->fail_until) {
            throw new RuntimeException("flaky failure {$count}/{$this->fail_until}");
        }

        if ($this->marker !== '') {
            file_put_contents($this->marker, "succeeded after {$count}");
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
