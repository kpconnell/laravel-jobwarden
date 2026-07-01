<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Workbench\App\Jobs\CrashJob;
use Workbench\App\Jobs\FlakyJob;
use Workbench\App\Jobs\MarkerJob;

/**
 * Full end-to-end: an in-process supervisor that proc_open's REAL jobwarden:run
 * children (via vendor/bin/testbench, which shares the Postgres DB through env).
 * Proves the success path, Tier-1 crash detection, and idempotent retry — the
 * core viability demo, automated.
 */
final class SupervisorE2ETest extends TestCase
{
    use RefreshesJobWardenSchema;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->engine() !== 'pgsql') {
            $this->markTestSkipped('E2E child spawning shares the DB via env — Postgres only.');
        }

        $this->root = dirname(__DIR__, 2); // /app

        config([
            'jobwarden.runtime_path' => $this->root.'/workbench/storage/jobwarden',
            'jobwarden.supervisor.run_command' => [PHP_BINARY, $this->root.'/vendor/bin/testbench', 'jobwarden:run'],
            'jobwarden.supervisor.run_cwd' => $this->root,
            'jobwarden.supervisor.graceful_timeout' => 2,
            'jobwarden.retry.backoff.strategy' => 'fixed',
            'jobwarden.retry.backoff.base' => 1,
            'jobwarden.retry.backoff.cap' => 2,
        ]);

        $this->setUpJobWardenSchema();
    }

    private function supervisor(int $capacity = 2): Supervisor
    {
        return new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
            $this->app->make(\JobWarden\Worker\WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(\JobWarden\Logging\JobLogger::class),
            $capacity,
        );
    }

    public function test_supervisor_runs_a_job_to_success_in_a_real_child(): void
    {
        $marker = $this->root.'/workbench/storage/jobwarden/marker-'.bin2hex(random_bytes(4)).'.txt';
        @unlink($marker);

        $job = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, ['marker' => $marker], ['idempotent' => true]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, $job->id, [JobState::Succeeded, JobState::Failed]);

        $this->assertSame(JobState::Succeeded, $final->state);
        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $this->assertSame(AttemptState::Succeeded, $attempt->state);
        $this->assertSame(0, $attempt->exit_code, 'supervisor recorded exit 0 (Tier 1)');
        $this->assertNotNull($attempt->child_pid, 'phase-2 stamp was written');
        $this->assertStringStartsWith('done:', (string) @file_get_contents($marker));

        @unlink($marker);
    }

    public function test_tier1_records_a_crashed_child_as_failed_with_the_signal(): void
    {
        $job = $this->app->make(JobWarden::class)->dispatch(CrashJob::class, [], ['idempotent' => false]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, $job->id, [JobState::Failed, JobState::Succeeded]);

        $this->assertSame(JobState::Failed, $final->state);
        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $this->assertSame(AttemptState::Failed, $attempt->state);
        $this->assertSame(9, $attempt->term_signal, 'supervisor recorded SIGKILL via waitpid');

        // The child's raw "dying words" (stderr, bypassing the Log facade) were
        // drained into job_logs on reap — no file hunting.
        $crashLog = \JobWarden\Models\JobLog::where('attempt_id', $attempt->id)
            ->where('step', 'process_output')->first();
        $this->assertNotNull($crashLog, 'crash output should be ingested into job_logs');
        $this->assertStringContainsString('JOBWARDEN-DYING-WORDS', (string) $crashLog->body_ref);
        $this->assertSame('error', $crashLog->level);

        // And the per-attempt file was removed after ingestion.
        $this->assertFileDoesNotExist($this->root.'/workbench/storage/jobwarden/logs/attempt-'.$attempt->id.'.log');
    }

    public function test_idempotent_job_retries_then_succeeds(): void
    {
        $marker = $this->root.'/workbench/storage/jobwarden/flaky-marker-'.bin2hex(random_bytes(4)).'.txt';
        $counter = $this->root.'/workbench/storage/jobwarden/flaky-counter-'.bin2hex(random_bytes(4)).'.txt';
        @unlink($marker);
        @unlink($counter);

        $job = $this->app->make(JobWarden::class)->dispatch(
            FlakyJob::class,
            ['marker' => $marker, 'counter' => $counter, 'fail_until' => 1],
            ['idempotent' => true, 'max_attempts' => 3]
        );

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, $job->id, [JobState::Succeeded, JobState::Failed], timeout: 25.0);

        $this->assertSame(JobState::Succeeded, $final->state);
        $this->assertSame(2, (int) $final->attempt_count, 'failed once, succeeded on attempt 2');
        $this->assertSame(2, JobAttempt::where('job_id', $job->id)->count());
        $this->assertStringStartsWith('succeeded after 2', (string) @file_get_contents($marker));

        @unlink($marker);
        @unlink($counter);
    }

    private function driveUntil(Supervisor $supervisor, string $jobId, array $states, float $timeout = 15.0): Job
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $supervisor->tick();
            $job = Job::find($jobId);

            // Terminal AND the child has been reaped (so Tier-1 exit metadata is
            // recorded) — the job state flips when the child *reports*, which can
            // be a beat before the process actually exits.
            if (in_array($job->state, $states, true) && $supervisor->load() === 0) {
                return $job;
            }

            usleep(120000);
        }

        return Job::find($jobId);
    }
}
