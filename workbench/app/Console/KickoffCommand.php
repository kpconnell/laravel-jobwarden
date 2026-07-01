<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use JobWarden\JobWarden;
use Illuminate\Console\Command;
use Workbench\App\Jobs\LongJob;

/**
 * A quick scheduled command that KICKS OFF a long-running job (onto the business
 * default lane) — the realistic pattern: a cron command that starts real work.
 */
final class KickoffCommand extends Command
{
    protected $signature = 'demo:kickoff {--seconds=60}';

    protected $description = 'Dispatch a long-running LongJob onto the business lane.';

    public function handle(JobWarden $jobwarden): int
    {
        $job = $jobwarden->dispatch(
            LongJob::class,
            ['seconds' => (int) $this->option('seconds')],
            ['idempotent' => true, 'max_attempts' => 3, 'name' => 'long-work']
        );

        $this->info("dispatched long job {$job->id}");

        return self::SUCCESS;
    }
}
