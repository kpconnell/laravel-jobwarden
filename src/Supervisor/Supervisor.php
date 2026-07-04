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
use JobWarden\Support\TransientFailure;
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

    /** Consecutive DETERMINISTIC (non-transient) tick failures tolerated before dying loudly. */
    private const TICK_FAILURE_LIMIT = 5;

    private ChildRegistry $children;

    private SignalState $signals;

    private ?Worker $worker = null;

    private ?WorkerContext $context = null;

    private bool $drainAnnounced = false;

    private ?float $drainStartedAt = null;

    private ?ForkExecutor $forkExecutor = null;

    private int $forkCount = 0;

    /** Consecutive full-capacity claims — hysteresis for ramping the poll to the floor. */
    private int $fullStreak = 0;

    /** The adaptive loop sleep (ms) computed each tick from claim demand; used by run(). */
    private int $nextPollMs = 500;

    /** Consecutive failed ticks of any kind — drives the retry backoff. */
    private int $tickFailures = 0;

    /** Consecutive failed ticks that were NOT transient — drives the die-loudly limit. */
    private int $deterministicTickFailures = 0;

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

    /** @param callable|null $onTick invoked each loop iteration (e.g. to supervise a bundled reaper). */
    public function run(?callable $onTick = null): void
    {
        $this->boot();

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                $this->tick();

                if ($onTick !== null) {
                    $onTick();
                }

                $this->tickFailures = 0;
                $this->deterministicTickFailures = 0;
            } catch (\Throwable $e) {
                // Exiting here is worse than the failure itself: the local reaper
                // treats a dead supervisor's (healthy, self-reporting) children as
                // reparented orphans and kills them — a DB blip must not translate
                // into killed work and parked non-idempotent jobs. Absorb what can
                // be outlasted; die loudly on what would recur every tick.
                $this->nextPollMs = $this->absorbTickFailure($e);
            }

            if ($this->shouldRecycle()) {
                Log::info('prefork: recycling master — draining in-flight forks then restarting for a fresh baseline', [
                    'role' => 'supervisor',
                    'forks' => $this->forkCount,
                ]);
                $this->signals->requestDrain();
            }

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

            usleep(max(1, $this->nextPollMs) * 1000);
        }

        $this->shutdown();
    }

    /**
     * A tick failed — decide whether that is survivable, and how long to back off
     * before the next one. Two regimes (see TransientFailure):
     *
     *  - TRANSIENT (lost connection / failover / deadlock): retried indefinitely
     *    with exponential backoff. The DB being down also stops our heartbeat, so
     *    the fleet sees the truth, and surviving the outage means claiming (and
     *    Tier-1 reaping of our children) resumes the moment it ends. Laravel
     *    reconnects a dead handle on the next query by itself.
     *
     *  - DETERMINISTIC (code/schema bug — would recur every tick): retried a few
     *    times, then rethrown. A supervisor that heartbeats while every tick
     *    fails looks healthy to all three recovery tiers while doing no work;
     *    dying loudly hands its children to Tier-2 and its restart to process
     *    supervision.
     *
     * @return int the backoff (ms) to sleep before the next tick
     */
    private function absorbTickFailure(\Throwable $e): int
    {
        $transient = TransientFailure::isTransient($e);
        $this->tickFailures++;
        $this->deterministicTickFailures = $transient ? 0 : $this->deterministicTickFailures + 1;

        if ($this->deterministicTickFailures >= self::TICK_FAILURE_LIMIT) {
            Log::critical('supervisor: tick failing deterministically; exiting so recovery sees an honest death', [
                'role' => 'supervisor',
                'consecutive_failures' => $this->deterministicTickFailures,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::error('supervisor: tick failed; backing off and continuing', [
            'role' => 'supervisor',
            'transient' => $transient,
            'consecutive_failures' => $this->tickFailures,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        $base = max(1, (int) config('jobwarden.supervisor.tick_failure_backoff_ms', 1000));

        return (int) min(30_000, $base * (2 ** min(10, $this->tickFailures - 1)));
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

            $this->nextPollMs = (int) config('jobwarden.supervisor.poll_min_ms', 50); // drain briskly

            return;
        }

        $this->admitter->admit();
        [$free, $claimed] = $this->claimAndSpawn();
        $this->nextPollMs = $this->adaptivePollMs($free, $claimed);
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

    /**
     * @return array{0:int,1:int} [free slots we tried to fill, jobs actually claimed]
     */
    private function claimAndSpawn(): array
    {
        $free = $this->capacity - $this->children->count();
        if ($free < 1) {
            return [0, 0];
        }

        $got = 0;
        $driver = $this->claimFactory->make();
        foreach ($driver->claim($this->context, $free) as $claimed) {
            $this->spawn($claimed->attemptId, $claimed->jobId, $claimed->fencingToken);
            $got++;
        }

        return [$free, $got];
    }

    /**
     * Adaptive poll cadence — the loop sleep tracks demand, sensed from the last claim's
     * fill ratio, so the supervisor stays responsive under load without hammering the DB
     * when idle:
     *   - all slots busy  -> floor (reap + refill promptly; there IS work in flight)
     *   - asked, got none -> idle (the queue is dry, back all the way off)
     *   - partial fill    -> a middle rung
     *   - full fill        -> speed up; a SECOND consecutive full fill drops to the floor
     *     (hysteresis, so one burst doesn't pin us hot).
     * Rungs: poll_min_ms (floor) / poll_interval_ms (one full fill) / poll_idle_ms (idle),
     * with the partial rung derived as half the idle ceiling.
     */
    private function adaptivePollMs(int $free, int $claimed): int
    {
        $min = (int) config('jobwarden.supervisor.poll_min_ms', 50);
        $full = (int) config('jobwarden.supervisor.poll_interval_ms', 500);
        $idle = (int) config('jobwarden.supervisor.poll_idle_ms', 5000);
        $partial = max($full, intdiv($idle, 2));

        if ($free === 0) {
            return $min;              // saturated — reap + refill fast
        }
        if ($claimed === 0) {
            $this->fullStreak = 0;
            return $idle;             // dry queue — back off
        }
        if ($claimed < $free) {
            $this->fullStreak = 0;
            return $partial;          // partial demand
        }

        $this->fullStreak++;          // full fill
        return $this->fullStreak >= 2 ? $min : $full;
    }

    private function spawn(string $attemptId, string $jobId, int $token): void
    {
        if ($this->isPrefork()) {
            $this->spawnForked($attemptId, $jobId, $token);

            return;
        }

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
            // A synchronous, KNOWN failure — the job was already claimed (a
            // dispatched attempt on a `running` job). Resolve it now instead of
            // stranding it for a reaper: fail the attempt and let recovery decide.
            $this->failToSpawn($attemptId, $jobId, $token, 'proc_open failed to launch the job child');

            return;
        }

        $status = proc_get_status($process);
        $pid = (int) $status['pid'];

        // PHASE-2 stamp: child pid/start-time/nonce, fencing-guarded. The child is
        // already alive; a failed stamp only blinds a reaper to it, not Tier-1
        // (we still register it below and waitpid-reap it), and the child reports
        // its own outcome regardless — so log and continue rather than abort.
        $childStart = $this->probe->startTime($pid);
        try {
            $this->stampWriter->phase2($attemptId, $token, $pid, (string) $childStart, $nonce);
        } catch (\Throwable $e) {
            Log::warning('phase-2 stamp failed; child running unstamped (Tier-1 still tracks it)', [
                'job' => $jobId, 'attempt' => $attemptId, 'pid' => $pid, 'error' => $e->getMessage(),
            ]);
        }

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

    private function isPrefork(): bool
    {
        return (string) config('jobwarden.supervisor.execution_mode') === 'prefork'
            && function_exists('pcntl_fork');
    }

    private function forkExecutor(): ForkExecutor
    {
        return $this->forkExecutor ??= new ForkExecutor($this->stampWriter, $this->probe);
    }

    /**
     * PREFORK spawn: fork the already-booted supervisor instead of proc_open'ing a fresh
     * PHP. The child runs the job in-process (ForkExecutor) and pcntl_exec's out; only the
     * parent returns here. The claim transaction has already committed, so we never fork
     * with an open transaction on the shared DB connection.
     */
    private function spawnForked(string $attemptId, string $jobId, int $token): void
    {
        $nonce = bin2hex(random_bytes(8));
        $logFile = $this->runtimePath().'/logs/attempt-'.$attemptId.'.log';

        $pid = $this->forkExecutor()->fork($attemptId, $token, $nonce, $logFile);
        if ($pid === -1) {
            $this->failToSpawn($attemptId, $jobId, $token, 'pcntl_fork failed to launch the job child');

            return;
        }

        $this->children->add(new ChildHandle(
            process: null,
            pid: $pid,
            attemptId: $attemptId,
            jobId: $jobId,
            fencingToken: $token,
            startedAt: microtime(true),
            logHandle: null,
            isFork: true,
        ));

        $this->forkCount++;
        Log::info('forked job child', ['job' => $jobId, 'attempt' => $attemptId, 'pid' => $pid, 'token' => $token]);
    }

    /**
     * PREFORK: has the master forked enough times to warrant a fresh baseline? When it
     * has, we request a drain — the loop finishes in-flight forks, run() returns, and the
     * launcher restarts the role — rather than pcntl_exec'ing ourselves, so recovery,
     * signal handling, and the co-reaper lifecycle all go through the normal path.
     */
    private function shouldRecycle(): bool
    {
        $after = (int) config('jobwarden.supervisor.prefork_recycle_after', 0);

        return $after > 0
            && $this->isPrefork()
            && $this->forkCount >= $after
            && ! $this->signals->isDraining();
    }

    /**
     * Tier-1 reap for a prefork child: pcntl_waitpid on its pid (there is no proc_open
     * resource). The child redirected its own stdout/stderr, so there's no logHandle here.
     */
    private function reapForked(ChildHandle $handle): void
    {
        $res = pcntl_waitpid($handle->pid, $status, WNOHANG);
        if ($res === 0) {
            $this->escalateStopIfRequested($handle);

            return; // still running
        }

        if ($res > 0) {
            $handle->exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null;
            $handle->termSignal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;
        }
        // $res === -1: no such child (already reaped / never ours) — treat as gone.

        $this->finalize($handle);
        $this->ingestProcessOutput($handle);
        $this->pidfile->delete($handle->attemptId);
        $this->children->forget($handle->attemptId);

        $crashed = $handle->termSignal !== null || ($handle->exitCode !== null && $handle->exitCode !== 0);
        Log::log($crashed ? 'warning' : 'info', 'reaped forked child', [
            'job' => $handle->jobId,
            'attempt' => $handle->attemptId,
            'pid' => $handle->pid,
            'exit_code' => $handle->exitCode,
            'term_signal' => $handle->termSignal,
            'duration_ms' => $handle->durationMs(),
        ]);
    }

    private function reap(): void
    {
        foreach ($this->children->all() as $handle) {
            if ($handle->isFork) {
                $this->reapForked($handle);

                continue;
            }

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
                $this->logStopLine($handle, sprintf(
                    'stopped by operator; child reaped (exit=%s signal=%s)',
                    $handle->exitCode ?? 'n/a',
                    $handle->termSignal ?? 'n/a'
                ));
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
        } catch (\JobWarden\StateMachine\Exceptions\StaleFencingTokenException|\JobWarden\StateMachine\Exceptions\IllegalTransitionException) {
            // A reaper/operator already took over this epoch or resolved the job —
            // leave that decision intact (and never let it crash the run loop).
        }
    }

    /**
     * proc_open returned false (fork/exec failure: ENOMEM, RLIMIT_NPROC, a broken
     * run_command). The claim already committed `queued → running` + a dispatched
     * attempt, so fail that attempt and hand the job to recovery — the same path a
     * boot-crashed child would take, just decided synchronously.
     */
    private function failToSpawn(string $attemptId, string $jobId, int $token, string $reason): void
    {
        $attempt = JobAttempt::query()->find($attemptId);
        if ($attempt === null) {
            return;
        }
        $job = $attempt->job;

        try {
            $this->connection()->transaction(function () use ($attempt, $job, $token, $reason): void {
                $this->stateMachine->applyAttemptTransition(
                    $attempt,
                    AttemptState::Failed,
                    TransitionContext::for(ActorType::Supervisor, null, $reason)->expectingToken($token)
                );
                if ($job !== null) {
                    $this->recovery->afterAttemptFailure($job, ActorType::Supervisor, $reason);
                }
            });
        } catch (\JobWarden\StateMachine\Exceptions\StaleFencingTokenException|\JobWarden\StateMachine\Exceptions\IllegalTransitionException) {
            // A reaper already took over this epoch — leave its decision intact.
        }

        Log::error('failed to spawn job child; attempt failed and recovery run', [
            'job' => $jobId, 'attempt' => $attemptId, 'reason' => $reason,
        ]);
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
        $grace = (int) config('jobwarden.supervisor.graceful_timeout', 10);

        if (! $handle->stopRequested) {
            $cancel = $this->connection()->table($this->tbl('jobs'))
                ->where('id', $handle->jobId)
                ->where('cancel_requested', true)
                ->first(['cancel_mode', 'cancel_reason']);

            if ($cancel !== null) {
                $handle->stopRequested = true;
                $handle->stopRequestedAt = microtime(true);
                $this->probe->signal($handle->pid, self::SIGTERM);
                Log::warning('stopping child (cancel requested): SIGTERM', ['attempt' => $handle->attemptId, 'pid' => $handle->pid]);

                $reason = (string) ($cancel->cancel_reason ?? '');
                $this->logStopLine($handle, sprintf(
                    '%s requested%s; SIGTERM sent (%ds grace, then SIGKILL)',
                    $cancel->cancel_mode === 'stop' ? 'stop' : 'cancel',
                    $reason !== '' ? ': '.$reason : '',
                    $grace
                ));
            }

            return;
        }

        if ($handle->stopRequestedAt !== null && (microtime(true) - $handle->stopRequestedAt) >= $grace) {
            $this->probe->signal($handle->pid, self::SIGKILL);
            Log::warning('force-killing child after grace window: SIGKILL', ['attempt' => $handle->attemptId, 'pid' => $handle->pid]);

            if (! $handle->sigkillLogged) {
                $handle->sigkillLogged = true;
                $this->logStopLine($handle, sprintf('grace window (%ds) expired; SIGKILL sent', $grace));
            }
        }
    }

    /**
     * Inject a stop-lifecycle line into the job's own log (the same seam a
     * reaper uses), so a canceled job's log shows request → signal → reap.
     * Best-effort: a log failure must never break signaling or finalize.
     */
    private function logStopLine(ChildHandle $handle, string $message): void
    {
        try {
            $this->jobLogger->write($handle->jobId, $handle->attemptId, 'warning', $message,
                ['source' => 'supervisor', 'pid' => $handle->pid], 'stop');
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function shutdown(): void
    {
        if ($this->worker === null) {
            return;
        }

        try {
            $this->registry->setState($this->worker, 'stopped', 'SIGTERM');
        } catch (\Throwable $e) {
            // Best-effort: with the DB unreachable the stale heartbeat already
            // tells Tier-3 the truth about this worker.
            Log::warning('supervisor: could not mark worker stopped on shutdown', ['error' => $e->getMessage()]);
        }

        Log::info('jobwarden supervisor stopped', ['worker_id' => (string) $this->worker->id]);
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
