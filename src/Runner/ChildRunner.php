<?php

declare(strict_types=1);

namespace JobWarden\Runner;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Logging\JobLogCapture;
use JobWarden\Models\JobAttempt;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Contracts\ProcessTitle;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What `jobwarden:run {attempt} --token --nonce` executes in the dedicated child
 * process (spec §6.1). Verifies the fencing token, writes the pidfile + nonce,
 * moves the attempt dispatched→running, runs the handler, and reports the
 * determinate outcome. A hard crash (no report) is caught by the supervisor's
 * Tier-1 waitpid instead.
 */
final class ChildRunner
{
    /** Head cap on the stored trace string — keeps the throw site, bounds bloat. */
    private const MAX_TRACE_CHARS = 8000;

    /** Set by the SIGTERM handler; handlers observe it via JobContext::stopRequested(). */
    private bool $stopRequested = false;

    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly RecoveryService $recovery,
        private readonly ProcessProbe $probe,
        private readonly Pidfile $pidfile,
        private readonly ProcessTitle $title,
        private readonly JobLogCapture $logs,
        private readonly HandlerFactory $handlers,
    ) {
    }

    public function run(string $attemptId, int $token, string $nonce): int
    {
        // --- instrumentation: separate framework-boot cost from starvation wait ---
        // boot_wall = time from PHP process start (REQUEST_TIME_FLOAT) to here;
        // boot_cpu  = CPU actually consumed to get here (getrusage). If boot_cpu is
        // large it's real compile/boot work (opcache's target); if boot_wall >>
        // boot_cpu the child mostly WAITED for a core (capacity/contention).
        $t0 = microtime(true);
        $bootWallMs = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? ($t0 - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000.0
            : -1.0;
        $bootCpuMs = $this->cpuMsTotal();

        $attempt = JobAttempt::query()->find($attemptId);
        if ($attempt === null || (int) $attempt->fencing_token !== $token) {
            return ExitCode::STALE_TOKEN;
        }

        $job = $attempt->job;
        if ($job === null) {
            return ExitCode::STALE_TOKEN;
        }

        $pid = getmypid();
        $startTime = $this->probe->startTime((int) $pid);
        $this->pidfile->write($attemptId, [
            'pid' => $pid,
            'start_time' => $startTime,
            'nonce' => $nonce,
            'token' => $token,
        ]);
        $this->title->set("jobwarden:run {$attemptId} ".substr($nonce, 0, 8));

        $snapshot = [
            'child_pid' => $pid,
            'child_start_time' => $startTime,
            'proc_nonce' => $nonce,
            'host_id' => $attempt->host_id,
        ];

        // dispatched → running (the first real work; carries the epoch).
        try {
            $this->stateMachine->applyAttemptTransition(
                $attempt,
                AttemptState::Running,
                TransitionContext::for(ActorType::Worker, null, 'started')
                    ->expectingToken($token)
                    ->withProcessSnapshot($snapshot)
            );
        } catch (IllegalTransitionException|StaleFencingTokenException) {
            $this->pidfile->delete($attemptId);

            return ExitCode::STALE_TOKEN;
        }

        $this->installGracefulStop();

        // Capture every Log facade call made during the job — handler logs AND
        // the supervisor wrappers below — into job_logs, scoped to this attempt.
        $this->logs->begin((string) $job->id, $attemptId);
        $this->logs->install();
        Log::info("Starting job {$job->job_class}", ['step' => 'starting', 'attempt' => (int) $attempt->attempt_number]);

        try {
            // Constructor param binding: stored params (JSON) are matched to
            // constructor parameters by name; the container resolves the rest.
            // A binding failure (missing/invalid param) throws here and is
            // recorded as a normal handler failure — loud, with the throw site.
            $handler = $this->handlers->make($job->job_class, (array) ($job->params ?? []));

            $context = new JobContext(
                (string) $job->id,
                $attemptId,
                (int) $attempt->attempt_number,
                fn (): bool => $this->stopRequested,
            );

            // handle() is invoked THROUGH the container: class-typed parameters
            // beyond JobContext are method-injected per-run (constructors are
            // data-only — see HandlerFactory). Keyed by class, not name, since
            // the interface doesn't pin the parameter's variable name.
            $handlerStart = microtime(true);
            app()->call([$handler, 'handle'], [JobContext::class => $context]);
            $handlerWallMs = (microtime(true) - $handlerStart) * 1000.0;
        } catch (\Throwable $e) {
            Log::error('Job failed: '.$e->getMessage(), ['step' => 'failed', 'exception' => $e::class]);
            $this->logs->end();

            return $this->fail($attempt, $job, $token, $e, $handler ?? null);
        }

        Log::info('child timing', [
            'step' => 'timing',
            'boot_wall_ms' => (int) round($bootWallMs),
            'boot_cpu_ms' => (int) round($bootCpuMs),
            'handler_wall_ms' => (int) round($handlerWallMs),
            'child_wall_ms' => (int) round((microtime(true) - $t0) * 1000.0),
            'child_cpu_ms' => (int) round($this->cpuMsTotal()),
        ]);
        Log::info('Job finished successfully', ['step' => 'finished']);
        $this->logs->end();

        return $this->succeed($attempt, $job, $token, $context->bufferedResult());
    }

    /** Total CPU (user+system) this process has consumed so far, in ms. */
    private function cpuMsTotal(): float
    {
        $ru = getrusage();

        return (
            ($ru['ru_utime.tv_sec'] ?? 0) + ($ru['ru_utime.tv_usec'] ?? 0) / 1e6
            + ($ru['ru_stime.tv_sec'] ?? 0) + ($ru['ru_stime.tv_usec'] ?? 0) / 1e6
        ) * 1000.0;
    }

    private function succeed(JobAttempt $attempt, $job, int $token, ?array $result): int
    {
        try {
            // ONE transaction: the attempt AND the job move together, so a crash
            // can never leave `attempt=succeeded, job=running` (the reconciliation
            // sweep is the backstop if the process dies before this even runs).
            // The handler's buffered result commits here too: a poller can never
            // see `succeeded` without it, and a fenced-out child's result rolls
            // back with the refused transition instead of clobbering the new owner.
            $this->connection()->transaction(function () use ($attempt, $job, $token, $result): void {
                $this->stateMachine->applyAttemptTransition(
                    $attempt,
                    AttemptState::Succeeded,
                    TransitionContext::for(ActorType::Worker, null, 'completed')->expectingToken($token)
                );
                $this->stateMachine->applyJobTransition(
                    $job,
                    JobState::Succeeded,
                    TransitionContext::for(ActorType::Worker, null, 'completed')
                );

                if ($result !== null) {
                    $job->forceFill(['result' => $result])->saveQuietly();
                }
            });
        } catch (StaleFencingTokenException) {
            // Fenced out (reaped while finishing). Leave authoritative state alone.
        }

        $this->pidfile->delete((string) $attempt->id);

        return ExitCode::SUCCESS;
    }

    private function fail(JobAttempt $attempt, $job, int $token, \Throwable $e, ?JobWardenJob $handler): int
    {
        $this->recordError($attempt, $job, $e);

        try {
            // ONE transaction: the attempt failing and the job's recovery decision
            // (retry / fail) commit together — no `attempt=failed, job=running`.
            $this->connection()->transaction(function () use ($attempt, $job, $token, $e): void {
                $this->stateMachine->applyAttemptTransition(
                    $attempt,
                    AttemptState::Failed,
                    TransitionContext::for(ActorType::Worker, null, 'handler threw')
                        ->expectingToken($token)
                        ->withContext(['error' => $e->getMessage()])
                );
                $this->recovery->afterAttemptFailure($job, ActorType::Worker, 'attempt failed: '.$e->getMessage());
            });
        } catch (StaleFencingTokenException) {
            // Fenced out — a reaper already took over.
        }

        $this->pidfile->delete((string) $attempt->id);

        return ExitCode::FAILURE;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    /**
     * Persist the failure as durable, structured state — with the stack trace —
     * on BOTH the attempt (`job_attempts.error`, spec §4.3 "class, message, trace
     * reference") and the Job itself (`jobs.last_error`, spec §4.2). This makes
     * "what failed and WHERE" answerable from the job/attempt rows directly,
     * rather than only by grepping log lines that carry the message but not the
     * throw site.
     */
    private function recordError(JobAttempt $attempt, $job, \Throwable $e): void
    {
        $error = $this->describe($e);

        $attempt->forceFill([
            'error' => $error,
            // finished_at is stamped by the terminal StateMachine transition (DB clock);
            // never here on the app clock — the reconcile sweep compares it DB-side.
        ])->save();

        $job->forceFill(['last_error' => $error])->saveQuietly();
    }

    /** @return array<string,mixed> */
    private function describe(\Throwable $e): array
    {
        $error = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $this->trace($e),
        ];

        if (($previous = $e->getPrevious()) !== null) {
            $error['previous'] = [
                'class' => $previous::class,
                'message' => $previous->getMessage(),
                'file' => $previous->getFile().':'.$previous->getLine(),
            ];
        }

        return $error;
    }

    /**
     * PHP orders trace frames most-recent-first, so a head cap keeps the throw
     * site and its immediate callers (the useful part) while bounding a
     * pathologically deep trace from bloating the row.
     */
    private function trace(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        return strlen($trace) > self::MAX_TRACE_CHARS
            ? substr($trace, 0, self::MAX_TRACE_CHARS)."\n… trace truncated"
            : $trace;
    }

    private function installGracefulStop(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        // The supervisor escalates SIGTERM → SIGKILL; a non-cooperative handler is
        // killed and recorded `stopped` by the supervisor. Cooperative handlers
        // poll JobContext::stopRequested() and can checkpoint within the grace
        // window. Flag-only on purpose: invoking handler code from an async
        // signal frame would re-enter it mid-operation (mid-transaction, even).
        pcntl_signal(SIGTERM, function (): void {
            $this->stopRequested = true;
        });
    }
}
