<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Exceptions\ProcessDied;
use JobWarden\Logging\JobLogger;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Fake\FakeProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Supervisor\ChildHandle;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;

/**
 * A supervisor-observed child death must leave the SAME error artifacts a
 * child-reported failure leaves: `job_attempts.error`, `jobs.last_error` and a
 * warning line in the job's own log. Without them a SIGKILLed (OOMed) job shows
 * "failed" with no error anywhere — a forensic dig instead of a dashboard glance.
 */
final class ChildDeathArtifactsTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_sigkilled_child_gets_a_synthesized_error_on_attempt_and_job(): void
    {
        [$job, $attempt] = $this->runningJob();

        $this->finalize($this->makeSupervisor(), $this->handle($job, $attempt, secondsAgo: 364.2, termSignal: 9));

        $attempt = JobAttempt::find($attempt->id);
        $job = Job::find($job->id);
        $this->assertSame(AttemptState::Failed, $attempt->state);
        $this->assertSame(JobState::Failed, $job->state);

        $expected = 'child killed by signal 9 (SIGKILL) after 364s without reporting — possible OOM or external kill';
        $this->assertSame(ProcessDied::class, $attempt->error['class']);
        $this->assertSame($expected, $attempt->error['message']);
        $this->assertSame((string) $attempt->id, $attempt->error['context']['attempt']);
        $this->assertSame(4242, $attempt->error['context']['pid']);
        $this->assertIsInt($attempt->error['context']['duration_ms']);
        $this->assertSame($attempt->error, $job->last_error);

        // Log parity with the reaper path: the job's log is never info-only for a dead job.
        $line = JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->first();
        $this->assertNotNull($line);
        $this->assertSame('warning', $line->level);
        $this->assertSame('supervisor: '.$expected.'; attempt failed', $line->body_ref);
    }

    public function test_child_reported_error_keeps_precedence_but_the_log_line_still_lands(): void
    {
        // The child recorded its error, then died before committing its Failed
        // transition — the supervisor must not overwrite the real throw site.
        $reported = ['class' => 'App\\Boom', 'message' => 'boom'];
        [$job, $attempt] = $this->runningJob();
        $attempt->forceFill(['error' => $reported])->save();
        $job->forceFill(['last_error' => $reported])->saveQuietly();

        $this->finalize($this->makeSupervisor(), $this->handle($job, $attempt, secondsAgo: 0.0, exitCode: 1));

        $this->assertSame($reported, JobAttempt::find($attempt->id)->error);
        $this->assertSame($reported, Job::find($job->id)->last_error);
        $this->assertSame(
            'supervisor: child exited with code 1 after 0s without reporting; attempt failed',
            JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->first()?->body_ref
        );
    }

    public function test_last_error_is_stamped_even_when_the_job_retries(): void
    {
        // Same semantics as the child path: last_error is the most recent
        // attempt's error, not only a terminal verdict — the dashboard shows
        // WHY a job is retrying.
        [$job, $attempt] = $this->runningJob(['idempotent' => true, 'max_attempts' => 3]);

        $this->finalize($this->makeSupervisor(), $this->handle($job, $attempt, secondsAgo: 1.0, termSignal: 11));

        $job = Job::find($job->id);
        $this->assertSame(JobState::Retrying, $job->state);
        $this->assertSame(ProcessDied::class, $job->last_error['class']);
        $this->assertStringContainsString('signal 11 (SIGSEGV)', $job->last_error['message']);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function runningJob(array $jobAttributes = []): array
    {
        $job = Job::create($jobAttributes + ['job_class' => 'X', 'state' => JobState::Running, 'attempt_count' => 1]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id, 'attempt_number' => 1,
            'state' => AttemptState::Running, 'fencing_token' => 1,
        ]);

        return [$job, $attempt];
    }

    private function handle(Job $job, JobAttempt $attempt, float $secondsAgo, ?int $termSignal = null, ?int $exitCode = null): ChildHandle
    {
        $handle = new ChildHandle(
            process: null,
            pid: 4242,
            attemptId: (string) $attempt->id,
            jobId: (string) $job->id,
            fencingToken: 1,
            startedAt: microtime(true) - $secondsAgo,
        );
        $handle->termSignal = $termSignal;
        $handle->exitCode = $exitCode;

        return $handle;
    }

    private function finalize(Supervisor $supervisor, ChildHandle $handle): void
    {
        (new \ReflectionMethod($supervisor, 'finalize'))->invoke($supervisor, $handle);
    }

    private function makeSupervisor(): Supervisor
    {
        return new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            new FakeProbe,   // never signal a real pid, regardless of OS
            $this->app->make(WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(JobLogger::class),
            2,
        );
    }
}
