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
    public function handle(JobContext $context): void
    {
        $steps = (int) ($context->params['steps'] ?? 3);
        $delay = (int) ($context->params['delay'] ?? 0);

        for ($i = 1; $i <= $steps; $i++) {
            Log::info("working step {$i}/{$steps}", ['step' => 'progress', 'i' => $i]);
            if ($delay > 0) {
                sleep($delay);
            }
        }

        Log::notice('all steps complete');
    }

    public function idempotent(): bool
    {
        return true;
    }
}
