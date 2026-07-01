<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Claim\WorkerContext;
use JobWarden\Logging\JobLogger;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\Worker;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan jobwarden:work` — a SUPERVISOR, not the job runner (spec §6.1).
 * Each claimed job is run in a dedicated child (jobwarden:run); the supervisor
 * proc_open's it, completes the phase-2 stamp, and reaps it (Tier 1) recording
 * the exit code / signal. A child that crashes without reporting is forced to a
 * terminal state here.
 */
final class Supervisor
{
    private const SIGTERM = 15;

    private const SIGKILL = 9;

    private ChildRegistry $children;

    private SignalState $signals;

    private ?Worker $worker = null;

    private ?WorkerContext $context = null;

    private bool $drainAnnounced = false;

    private ?float $drainStartedAt = null;

    public function __construct(
        private readonly ClaimDriverFactory $claimFactory,
        private readonly Admitter $admitter,
        private readonly ProcessStampWriter $stampWriter,
        private readonly ProcessProbe $probe,
        private readonly WorkerRegistry $registry,
        private readonly StateMachine $stateMachine,
        private readonly RecoveryService $recovery,
        private readonly HostIdentity $host,
        private readonly Pidfile $pidfile,
        private readonly JobLogger $jobLogger,
        private readonly int $capacity,
        private readonly string $lane = 'default',
    ) {
        $this->children = new ChildRegistry;
        $this->signals = new SignalState;
    }

    public function boot(): Worker
    {
        if ($this->worker !== null) {
            return $this->worker;
        }

        $this->worker = $this->registry->register('supervisor', $this->capacity, ['lane' => $this->lane]);

        $pid = (int) getmypid();
        $this->context = new WorkerContext(
            workerId: (string) $this->worker->id,
            hostId: $this->host->hostId(),
            hostname: $this->host->hostname(),
            supervisorPid: $pid,
            supervisorStartTime: $this->probe->startTime($pid) !== null ? (int) $this->probe->startTime($pid) : null,
            lane: $this->lane,
        );

        $this->installSignalHandlers();

        Log::info('jobwarden supervisor started', [
            'role' => 'supervisor',
            'worker_id' => (string) $this->worker->id,
            'host_id' => $this->context->hostId,
            'pid' => $pid,
            'capacity' => $this->capacity,
        ]);

        return $this->worker;
    }

    public function run(): void
    {
        $this->boot();

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $this->tick();

            if ($this->signals->isDraining()) {
                if ($this->children->isEmpty()) {
                    break; // clean drain: all in-flight work finished
                }
                if ($this->drainTimedOut()) {
                    Log::warning('drain timeout reached; abandoning in-flight children', [
                        'in_flight' => $this->children->count(),
                        'drain_timeout' => $this->drainTimeout(),
                        'note' => 'children self-report their outcome; a reaper recovers any the container then kills',
                    ]);
                    break;
                }
            }

            usleep(((int) config('jobwarden.supervisor.poll_interval_ms', 500)) * 1000);
        }

        $this->shutdown();
    }

    private function drainTimedOut(): bool
    {
        $timeout = $this->drainTimeout();

        return $timeout > 0
            && $this->drainStartedAt !== null
            && (microtime(true) - $this->drainStartedAt) >= $timeout;
    }

    private function drainTimeout(): int
    {
        return (int) config('jobwarden.supervisor.drain_timeout', 0);
    }

    /** One iteration: reap finished children, admit + claim, escalate stops, heartbeat. */
    public function tick(): void
    {
        if ($this->worker === null) {
            $this->boot();
        }

        // Heartbeat first — also self-heals the worker row, guaranteeing it
        // exists before any claim writes an attempt referencing worker_id.
        $this->registry->heartbeat($this->worker, $this->children->count());

        $this->reap();

        if ($this->signals->isDraining()) {
            if (! $this->drainAnnounced) {
                $this->drainAnnounced = true;
                $this->drainStartedAt = microtime(true);
                Log::info('supervisor draining (stop received); no longer claiming', [
                    'in_flight' => $this->children->count(),
                    'drain_timeout' => $this->drainTimeout(),
                ]);
            }

            return;
        }

        $this->admitter->admit();
        $this->claimAndSpawn();
    }

    public function drain(): void
    {
        $this->signals->requestDrain();
    }

    public function load(): int
    {
        return $this->children->count();
    }

    public function children(): ChildRegistry
    {
        return $this->children;
    }

    // -- internals ---------------------------------------------------------

    private function claimAndSpawn(): void
    {
        $free = $this->capacity - $this->children->count();
        if ($free < 1) {
            return;
        }

        $driver = $this->claimFactory->make();
        foreach ($driver->claim($this->context, $free) as $claimed) {
            $this->spawn($claimed->attemptId, $claimed->jobId, $claimed->fencingToken);
        }
    }

    private function spawn(string $attemptId, string $jobId, int $token): void
    {
        $nonce = bin2hex(random_bytes(8));
        $command = array_merge($this->runCommandPrefix(), [
            $attemptId,
            '--token='.$token,
            '--nonce='.$nonce,
        ]);

        $logFile = $this->runtimePath().'/logs/attempt-'.$attemptId.'.log';
        if (! is_dir(dirname($logFile))) {
            @mkdir(dirname($logFile), 0775, true);
        }
        $log = fopen($logFile, 'a');

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $log,
            2 => $log,
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->runCwd(), null);
        if (! is_resource($process)) {
            @fclose($log);

            return;
        }

        $status = proc_get_status($process);
        $pid = (int) $status['pid'];

        // PHASE-2 stamp: child pid/start-time/nonce, fencing-guarded.
        $childStart = $this->probe->startTime($pid);
        $this->stampWriter->phase2($attemptId, $token, $pid, (string) $childStart, $nonce);

        $this->children->add(new ChildHandle(
            process: $process,
            pid: $pid,
            attemptId: $attemptId,
            jobId: $jobId,
            fencingToken: $token,
            startedAt: microtime(true),
            logHandle: $log,
        ));

        Log::info('spawned job child', ['job' => $jobId, 'attempt' => $attemptId, 'pid' => $pid, 'token' => $token]);
    }

    private function reap(): void
    {
        foreach ($this->children->all() as $handle) {
            $status = proc_get_status($handle->process);

            if ($status['running']) {
                $this->escalateStopIfRequested($handle);

                continue;
            }

            $handle->exitCode = ($status['exitcode'] ?? -1) >= 0 ? (int) $status['exitcode'] : null;
            $handle->termSignal = ($status['signaled'] ?? false) ? (int) ($status['termsig'] ?? 0) : null;

            proc_close($handle->process);
            if (is_resource($handle->logHandle)) {
                @fclose($handle->logHandle);
            }

            $this->finalize($handle);
            $this->ingestProcessOutput($handle);
            $this->pidfile->delete($handle->attemptId);
            $this->children->forget($handle->attemptId);

            $crashed = $handle->termSignal !== null || ($handle->exitCode !== null && $handle->exitCode !== 0);
            Log::log($crashed ? 'warning' : 'info', 'reaped job child', [
                'job' => $handle->jobId,
                'attempt' => $handle->attemptId,
                'pid' => $handle->pid,
                'exit_code' => $handle->exitCode,
                'term_signal' => $handle->termSignal,
                'duration_ms' => $handle->durationMs(),
            ]);
        }
    }

    /**
     * Tier 1 (spec §5.4): record exit metadata; if the child already reported a
     * determinate outcome, do nothing more — otherwise force a terminal state.
     */
    private function finalize(ChildHandle $handle): void
    {
        $this->recordExit($handle);

        $attempt = JobAttempt::query()->find($handle->attemptId);
        // Already terminal (child reported), or already orphaned by a reaper (which
        // now owns the recovery) — record exit metadata only, don't force a state.
        if ($attempt === null || $attempt->state->isTerminal() || $attempt->state === AttemptState::Orphaned) {
            return;
        }

        $job = $attempt->job;
        $context = TransitionContext::for(ActorType::Supervisor)
            ->expectingToken($handle->fencingToken)
            ->withContext(['exit_code' => $handle->exitCode, 'term_signal' => $handle->termSignal]);

        try {
            if ($handle->stopRequested) {
                $this->stateMachine->applyAttemptTransition($attempt, AttemptState::Stopped, (clone $context)->because('stopped by operator'));
                if ($job !== null) {
                    $this->stateMachine->applyJobTransition($job, JobState::Stopped, TransitionContext::for(ActorType::Supervisor, null, 'stopped by operator'));
                }

                return;
            }

            $this->stateMachine->applyAttemptTransition($attempt, AttemptState::Failed, (clone $context)->because('child died without reporting'));
            if ($job !== null) {
                $this->recovery->afterAttemptFailure(
                    $job,
                    ActorType::Supervisor,
                    sprintf('child died: exit=%s signal=%s', $handle->exitCode ?? 'n/a', $handle->termSignal ?? 'n/a')
                );
            }
        } catch (\JobWarden\StateMachine\Exceptions\StaleFencingTokenException) {
            // A reaper already took over this epoch — leave its decision intact.
        }
    }

    private function recordExit(ChildHandle $handle): void
    {
        $this->connection()->table($this->tbl('job_attempts'))
            ->where('id', $handle->attemptId)
            ->update([
                'exit_code' => $handle->exitCode,
                'term_signal' => $handle->termSignal,
                'duration_ms' => $handle->durationMs(),
                'updated_at' => $this->connection()->raw('CURRENT_TIMESTAMP'),
            ]);
    }

    private function escalateStopIfRequested(ChildHandle $handle): void
    {
        if (! $handle->stopRequested) {
            $cancel = $this->connection()->table($this->tbl('jobs'))
                ->where('id', $handle->jobId)
                ->where('cancel_requested', true)
                ->exists();

            if ($cancel) {
                $handle->stopRequested = true;
                $handle->stopRequestedAt = microtime(true);
                $this->probe->signal($handle->pid, self::SIGTERM);
                Log::warning('stopping child (cancel requested): SIGTERM', ['attempt' => $handle->attemptId, 'pid' => $handle->pid]);
            }

            return;
        }

        $grace = (int) config('jobwarden.supervisor.graceful_timeout', 10);
        if ($handle->stopRequestedAt !== null && (microtime(true) - $handle->stopRequestedAt) >= $grace) {
            $this->probe->signal($handle->pid, self::SIGKILL);
            Log::warning('force-killing child after grace window: SIGKILL', ['attempt' => $handle->attemptId, 'pid' => $handle->pid]);
        }
    }

    private function shutdown(): void
    {
        if ($this->worker !== null) {
            $this->registry->setState($this->worker, 'stopped', 'SIGTERM');
            Log::info('jobwarden supervisor stopped', ['worker_id' => (string) $this->worker->id]);
        }
    }

    /**
     * Drain the child's raw stdout/stderr (a fatal/OOM's dying words — things
     * that bypass the Log facade) into job_logs on reap, then delete the file.
     * Everything a job emits ends up queryable in the DB; nobody hunts for files.
     */
    private function ingestProcessOutput(ChildHandle $handle): void
    {
        $path = $this->runtimePath().'/logs/attempt-'.$handle->attemptId.'.log';
        if (! is_file($path)) {
            return;
        }

        $size = (int) (@filesize($path) ?: 0);
        if ($size === 0) {
            @unlink($path); // nothing raw was emitted — drop the empty file

            return;
        }

        $max = 65_536; // bound the row: keep the last 64 KB
        $content = rtrim($this->readTail($path, $max));
        $crashed = $handle->termSignal !== null || ($handle->exitCode !== null && $handle->exitCode !== 0);

        if ($content !== '') {
            try {
                $this->jobLogger->write(
                    $handle->jobId,
                    $handle->attemptId,
                    $crashed ? 'error' : 'info',
                    $content,
                    [
                        'source' => 'process_output',
                        'exit_code' => $handle->exitCode,
                        'term_signal' => $handle->termSignal,
                        'truncated' => $size > $max,
                    ],
                    'process_output',
                );
            } catch (\Throwable) {
                // best-effort
            }
        }

        @unlink($path);
    }

    private function readTail(string $path, int $max): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        $size = (int) (@filesize($path) ?: 0);
        if ($size > $max) {
            fseek($handle, -$max, SEEK_END);
        }
        $data = stream_get_contents($handle);
        fclose($handle);

        return $data === false ? '' : $data;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $drain = function (): void {
            $this->signals->requestDrain();
        };
        pcntl_signal(self::SIGTERM, $drain);
        pcntl_signal(2, $drain); // SIGINT
    }

    /** @return string[] */
    private function runCommandPrefix(): array
    {
        $configured = config('jobwarden.supervisor.run_command');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [PHP_BINARY, base_path('artisan'), 'jobwarden:run'];
    }

    private function runCwd(): string
    {
        return (string) (config('jobwarden.supervisor.run_cwd') ?: base_path());
    }

    private function runtimePath(): string
    {
        return (string) config('jobwarden.runtime_path');
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
