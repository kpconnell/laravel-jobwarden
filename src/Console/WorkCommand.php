<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Logging\JobLogger;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Supervisor\CoReaper;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Console\Command;

/**
 * Per-host supervisor (spec §10.2): claims jobs, spawns + waitpids children
 * (Tier 1). It also brings its own Tier-2 local reaper as a co-resident child
 * process (see CoReaper), so running `jobwarden:work` is all a host needs — you
 * can't forget the reaper. Disable with `jobwarden.supervisor.bundle_reaper` only
 * for advanced split topologies.
 */
final class WorkCommand extends Command
{
    protected $signature = 'jobwarden:work
        {--capacity= : max concurrent jobs}
        {--lane=default : the claim lane to serve (the scheduled tier uses "scheduled")}
        {--drain-timeout= : seconds to wait for in-flight jobs on SIGTERM (0 = indefinitely)}
        {--once : run a single tick and exit}';

    protected $description = 'Run a JobWarden supervisor: claim jobs from a lane and run them in child processes.';

    public function handle(
        ClaimDriverFactory $claimFactory,
        Admitter $admitter,
        ProcessStampWriter $stampWriter,
        ProcessProbe $probe,
        WorkerRegistry $registry,
        StateMachine $stateMachine,
        RecoveryService $recovery,
        HostIdentity $host,
        Pidfile $pidfile,
        JobLogger $jobLogger,
    ): int {
        $capacity = (int) ($this->option('capacity') ?: config('jobwarden.supervisor.capacity', 5));
        $lane = (string) ($this->option('lane') ?: 'default');

        if ($this->option('drain-timeout') !== null) {
            config(['jobwarden.supervisor.drain_timeout' => (int) $this->option('drain-timeout')]);
        }

        $supervisor = new Supervisor(
            $claimFactory, $admitter, $stampWriter, $probe, $registry,
            $stateMachine, $recovery, $host, $pidfile, $jobLogger, $capacity, $lane,
        );

        $worker = $supervisor->boot();
        $this->info("jobwarden:work supervisor {$worker->id} (host {$worker->host_id}) lane={$lane} capacity={$capacity}");

        if ($this->option('once')) {
            $supervisor->tick();
            $this->line('tick complete; load='.$supervisor->load());

            return self::SUCCESS;
        }

        // Bring a co-resident local reaper as a SEPARATE child so Tier-2 recovery
        // is never accidentally left off. It outlives a crash of this process; on a
        // clean drain we stop it below.
        $coReaper = $this->startCoReaper();

        $supervisor->run($coReaper !== null ? fn () => $coReaper->ensureAlive() : null);

        $coReaper?->stop((int) config('jobwarden.supervisor.graceful_timeout', 10));
        $this->info('supervisor drained and stopped.');

        return self::SUCCESS;
    }

    private function startCoReaper(): ?CoReaper
    {
        if (! config('jobwarden.supervisor.bundle_reaper', true)) {
            return null; // advanced split: operator runs jobwarden:reap:local itself
        }

        $coReaper = new CoReaper($this->reaperCommand(), $this->reaperCwd());
        $coReaper->start();
        $this->info('bundled a co-resident local reaper (Tier-2) — no separate jobwarden:reap:local needed.');

        return $coReaper;
    }

    /**
     * The command to spawn the bundled reaper — reuses whatever runs the job
     * children (jobwarden.supervisor.run_command) with the subcommand swapped.
     *
     * @return string[]
     */
    private function reaperCommand(): array
    {
        $run = config('jobwarden.supervisor.run_command');
        if (is_array($run) && $run !== []) {
            return array_merge(array_slice($run, 0, -1), ['jobwarden:reap:local']);
        }

        return [PHP_BINARY, base_path('artisan'), 'jobwarden:reap:local'];
    }

    private function reaperCwd(): string
    {
        return (string) (config('jobwarden.supervisor.run_cwd') ?: base_path());
    }
}
