<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Log;

/**
 * A long-running unit of work: logs a heartbeat each second so you can watch it
 * run via the API and then cancel it mid-flight (a busy process to crush).
 * Cooperates with graceful stop — exits its loop when the supervisor asks.
 */
final class LongJob implements JobWardenJob
{
    public function __construct(private readonly int $seconds = 60)
    {
    }

    public function handle(JobContext $context): void
    {
        for ($i = 1; $i <= $this->seconds; $i++) {
            if ($context->stopRequested()) {
                Log::info("stop requested — checkpointing at {$i}/{$this->seconds}", ['step' => 'stop']);

                return;
            }
            Log::info("working... {$i}/{$this->seconds}", ['step' => 'work']);
            sleep(1);
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
