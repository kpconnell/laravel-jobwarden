<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Log;

/**
 * A long-running unit of work: logs a heartbeat each second so you can watch it
 * run via the API and then cancel it mid-flight (a busy process to crush).
 */
final class LongJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $n = (int) ($context->params['seconds'] ?? 60);
        for ($i = 1; $i <= $n; $i++) {
            Log::info("working... {$i}/{$n}", ['step' => 'work']);
            sleep(1);
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
