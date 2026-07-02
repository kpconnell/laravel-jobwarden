<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Log;

/**
 * Emits progress via the standard Log facade — proves Log:: calls land in
 * job_logs and (with `--delay`) that they're visible LIVE while the job runs.
 */
final class ChattyJob implements JobWardenJob
{
    public function __construct(
        private readonly int $steps = 3,
        private readonly int $delay = 0,
    ) {
    }

    public function handle(JobContext $context): void
    {
        for ($i = 1; $i <= $this->steps; $i++) {
            Log::info("working step {$i}/{$this->steps}", ['step' => 'progress', 'i' => $i]);
            if ($this->delay > 0) {
                sleep($this->delay);
            }
        }

        Log::notice('all steps complete');
    }

    public function idempotent(): bool
    {
        return true;
    }
}
