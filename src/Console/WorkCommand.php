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
use JobWarden\Supervisor\Supervisor;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Console\Command;

/**
 * Per-host supervisor (spec §10.2): claims jobs, spawns + waitpids children
 * (Tier 1).
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

        $supervisor->run();
        $this->info('supervisor drained and stopped.');

        return self::SUCCESS;
    }
}
