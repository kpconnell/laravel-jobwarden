<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Reaper\LocalReaper;
use JobWarden\Support\SystemdNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Per-host local reaper (Tier 2, spec §10.2). Verifies process stamps, refreshes
 * the host lease, kills reparented children of dead supervisors, and self-fences
 * on lost connectivity. Run one per host under systemd Restart=always + watchdog.
 */
final class LocalReaperCommand extends Command
{
    protected $signature = 'jobwarden:reap:local {--once : single scan} {--interval= : seconds between scans}';

    protected $description = 'Per-host local reaper: verify stamps, kill reparented orphans, hold the host lease.';

    private bool $stopping = false;

    public function handle(LocalReaper $reaper): int
    {
        $worker = $reaper->boot();
        $this->line("jobwarden:reap:local {$worker->id} on host {$reaper->hostId()}");

        if ($this->option('once')) {
            $reaper->tick();

            return self::SUCCESS;
        }

        $this->installSignals();
        $interval = (int) ($this->option('interval') ?: config('jobwarden.reaper.local_scan_interval', 5));

        $systemd = new SystemdNotifier;
        $systemd->ready(); // Type=notify: tell systemd we're up.

        while (! $this->stopping) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $alive = $reaper->tick();
            if (! $alive) {
                // Self-fenced: exit so systemd restarts us clean.
                Log::critical('local reaper exiting after self-fence');

                return self::FAILURE;
            }

            // Pet the watchdog only on a HEALTHY scan — a wedged reaper that
            // stops ticking lets WatchdogSec expire and systemd restarts it.
            $systemd->watchdog();

            sleep(max(1, $interval));
        }

        $systemd->stopping();
        Log::info('jobwarden local reaper stopped');

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
        pcntl_signal(15, $stop);
        pcntl_signal(2, $stop);
    }
}
