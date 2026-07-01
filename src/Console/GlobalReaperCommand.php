<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Reaper\GlobalReaper;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The HA global reaper (Tier 3, spec §10.2). Run it on ≥1 host; the DB leader
 * lease makes running it everywhere safe (only one scans at a time).
 */
final class GlobalReaperCommand extends Command
{
    protected $signature = 'jobwarden:reap:global {--once : single scan} {--interval= : seconds between scans}';

    protected $description = 'HA global reaper: declare dead hosts via stale leases and orphan their attempts.';

    private bool $stopping = false;

    public function handle(GlobalReaper $reaper, WorkerRegistry $registry): int
    {
        $worker = $registry->register('global_reaper');
        $reaperId = (string) $worker->id;

        Log::info('jobwarden global reaper started', [
            'role' => 'global_reaper',
            'worker_id' => $reaperId,
            'host_id' => $worker->host_id,
            'pid' => getmypid(),
            'budget_sec' => $reaper->budgetSeconds(),
        ]);

        if ($this->option('once')) {
            $leader = $reaper->tick($reaperId);
            $this->line($leader ? 'scanned (leader)' : 'skipped (not leader)');

            return self::SUCCESS;
        }

        $this->installSignals();
        $interval = (int) ($this->option('interval') ?: config('jobwarden.reaper.global_scan_interval', 5));

        while (! $this->stopping) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $reaper->tick($reaperId);
            $registry->heartbeat($worker);

            sleep(max(1, $interval));
        }

        $registry->setState($worker, 'stopped', 'SIGTERM');
        Log::info('jobwarden global reaper stopped', ['worker_id' => $reaperId]);

        return self::SUCCESS;
    }

    private function installSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(15, $stop); // SIGTERM
        pcntl_signal(2, $stop);  // SIGINT
    }
}
