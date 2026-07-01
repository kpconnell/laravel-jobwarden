<?php

declare(strict_types=1);

namespace JobWarden\Tests\Runner;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Runner\ChildRunner;
use JobWarden\Runner\ExitCode;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\MarkerJob;

/**
 * The child's logic, run in-process (no subprocess): token verification,
 * dispatched→running, handler execution, and the determinate outcome.
 */
final class ChildRunnerTest extends TestCase
{
    use RefreshesJobWardenSchema;

    private string $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = sys_get_temp_dir().'/jobwarden-runner-'.bin2hex(random_bytes(4));
        config(['jobwarden.runtime_path' => $this->runtime]);
        $this->setUpJobWardenSchema();
    }

    private function runner(): ChildRunner
    {
        return $this->app->make(ChildRunner::class);
    }

    public function test_success_path_runs_the_handler_and_reports_succeeded(): void
    {
        $marker = $this->runtime.'/marker.txt';
        [$job, $attempt] = $this->dispatched(MarkerJob::class, ['marker' => $marker], idempotent: true);

        $code = $this->runner()->run($attempt->id, 1, 'nonce-abc');

        $this->assertSame(ExitCode::SUCCESS, $code);
        $this->assertSame(AttemptState::Succeeded, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Succeeded, Job::find($job->id)->state);
        $this->assertStringStartsWith('done:', (string) @file_get_contents($marker));
    }

    public function test_non_idempotent_failure_fails_the_job(): void
    {
        [$job, $attempt] = $this->dispatched(FailingJob::class, ['message' => 'kaboom'], idempotent: false, maxAttempts: 1);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $this->assertSame(AttemptState::Failed, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Failed, Job::find($job->id)->state);

        // The failure is captured as durable structured state — message, throw
        // site, AND stack trace — on the attempt (spec §4.3)...
        $error = JobAttempt::find($attempt->id)->error;
        $this->assertSame('kaboom', $error['message']);
        $this->assertStringContainsString('FailingJob', $error['file'], 'the throw site is recorded');
        $this->assertNotEmpty($error['trace'] ?? '', 'the stack trace is captured, not just the message');
        $this->assertStringStartsWith('#0', $error['trace']);

        // ...and mirrored onto the Job itself as a first-class attribute (spec
        // §4.2 last_error), so "what failed and where" needs no log grepping.
        $lastError = Job::find($job->id)->last_error;
        $this->assertSame('kaboom', $lastError['message']);
        $this->assertNotEmpty($lastError['trace'] ?? '');
    }

    public function test_idempotent_failure_within_budget_schedules_a_retry(): void
    {
        [$job, $attempt] = $this->dispatched(FailingJob::class, [], idempotent: true, maxAttempts: 3);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $this->assertSame(AttemptState::Failed, JobAttempt::find($attempt->id)->state);

        $job = Job::find($job->id);
        $this->assertSame(JobState::Retrying, $job->state);
        $this->assertTrue($job->available_at->isFuture(), 'retry should be delayed by backoff');
    }

    public function test_stale_token_is_refused_and_writes_nothing(): void
    {
        [$job, $attempt] = $this->dispatched(MarkerJob::class, [], idempotent: true);
        // Attempt's real epoch is 1; the child arrives carrying a stale token.
        $code = $this->runner()->run($attempt->id, 99, 'n');

        $this->assertSame(ExitCode::STALE_TOKEN, $code);
        $this->assertSame(AttemptState::Dispatched, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Running, Job::find($job->id)->state);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function dispatched(string $jobClass, array $params, bool $idempotent, int $maxAttempts = 1): array
    {
        $job = Job::create([
            'job_class' => $jobClass,
            'state' => JobState::Running,
            'params' => $params,
            'idempotent' => $idempotent,
            'max_attempts' => $maxAttempts,
            'attempt_count' => 1,
            'backoff_strategy' => 'fixed',
        ]);

        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Dispatched,
            'fencing_token' => 1,
            'host_id' => 'host',
        ]);

        return [$job, $attempt];
    }
}
