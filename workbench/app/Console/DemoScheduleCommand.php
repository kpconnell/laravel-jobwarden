<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use JobWarden\JobWarden;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Workbench\App\Jobs\MarkerJob;

/**
 * Create a recurring schedule whose last_evaluated_at is set in the past, so the
 * next evaluation sees a backlog of "missed" occurrences — for testing missed-run
 * detection and multi-scheduler safety.
 */
final class DemoScheduleCommand extends Command
{
    protected $signature = 'jobwarden:demo:schedule
        {--cron= : cron expression (default: every minute)}
        {--missed-minutes=20 : pretend the scheduler was down this long}
        {--policy=run_all : missed policy}
        {--command= : schedule an artisan command instead of a job (e.g. demo:exit)}';

    protected $description = 'Create a schedule (job or artisan command) with a backlog of missed occurrences.';

    public function handle(JobWarden $jobwarden): int
    {
        $cron = (string) ($this->option('cron') ?: '* * * * *');
        $opts = ['missed_policy' => (string) $this->option('policy'), 'overlap_policy' => 'allow'];

        if ($command = $this->option('command')) {
            $schedule = $jobwarden->scheduleCommand('demo-cmd-'.bin2hex(random_bytes(3)), $cron, (string) $command, [], $opts);
        } else {
            $schedule = $jobwarden->schedule('demo-sched-'.bin2hex(random_bytes(3)), $cron, MarkerJob::class, ['sleep' => 0], $opts);
        }

        $schedule->forceFill([
            'last_evaluated_at' => Carbon::now()->subMinutes((int) $this->option('missed-minutes')),
        ])->save();

        $this->info("created schedule {$schedule->id} (cron='{$schedule->cron_expression}', last_evaluated {$this->option('missed-minutes')}m ago)");

        return self::SUCCESS;
    }
}
