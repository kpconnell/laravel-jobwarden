<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Models\Schedule;
use JobWarden\Scheduling\ScheduleEvaluator;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Durable scheduler (spec §7) — multi-instance safe. Registers as a `scheduler`
 * worker and, each tick, evaluates every enabled schedule (each guarded by a row
 * lock + the schedule_runs unique constraint), enqueuing due/missed occurrences.
 */
final class ScheduleCommand extends Command
{
    protected $signature = 'jobwarden:schedule {--once : evaluate once and exit} {--interval= : seconds between ticks}';

    protected $description = 'Run the JobWarden scheduler (cron + one-time, with missed-run detection).';

    private bool $stopping = false;

    public function handle(ScheduleEvaluator $evaluator, WorkerRegistry $registry): int
    {
        $worker = $registry->register('scheduler');
        Log::info('jobwarden scheduler started', ['worker_id' => (string) $worker->id, 'host_id' => $worker->host_id, 'pid' => getmypid()]);

        $tick = function () use ($evaluator, $worker): int {
            $total = 0;
            foreach (Schedule::query()->where('enabled', true)->pluck('id') as $id) {
                try {
                    $total += $evaluator->evaluate((string) $id, (string) $worker->id);
                } catch (\Throwable $e) {
                    // Per-schedule isolation: one bad schedule (e.g. an invalid
                    // cron, a transient error) must NEVER crash-loop the whole
                    // tier — OS restart can't fix a deterministic crash. Log and
                    // carry on; the other schedules still fire.
                    Log::error('schedule evaluation failed; skipping', [
                        'schedule_id' => (string) $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $total;
        };

        if ($this->option('once')) {
            $this->line('enqueued '.$tick().' job(s)');

            return self::SUCCESS;
        }

        $this->installSignals();
        $interval = (int) ($this->option('interval') ?: config('jobwarden.scheduler.tick_interval', 5));

        while (! $this->stopping) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $tick();
            $registry->heartbeat($worker);
            sleep(max(1, $interval));
        }

        $registry->setState($worker, 'stopped', 'SIGTERM');
        Log::info('jobwarden scheduler stopped', ['worker_id' => (string) $worker->id]);

        return self::SUCCESS;
    }

    private function installSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = fn () => $this->stopping = true;
        pcntl_signal(15, $stop);
        pcntl_signal(2, $stop);
    }
}
