<?php

declare(strict_types=1);

namespace JobWarden\Tests\Runner;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Runner\ChildRunner;
use JobWarden\Runner\ExitCode;
use JobWarden\States\AttemptState;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Workbench\App\Jobs\BatchAwareJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\MarkerJob;
use Workbench\App\Jobs\ResultJob;
use Workbench\App\Jobs\TypedParamsJob;

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
        $this->assertNull(Job::find($job->id)->result, 'a handler that never calls result() leaves it null');
    }

    public function test_a_batch_members_context_carries_its_batch(): void
    {
        // The ONLY wiring between a real member and JobContext::batch(): without
        // it every finalizer sees null and the feature is dead in production
        // while a hand-constructed JobContext test still passes.
        $batch = Batch::create([
            'name' => 'nightly-sync',
            'state' => BatchState::Running,
            'failure_policy' => 'continue',
            'total_jobs' => 1,
            'running_count' => 1,
        ]);
        [$job, $attempt] = $this->dispatched(BatchAwareJob::class, [], idempotent: true, batchId: $batch->id);

        $this->assertSame(ExitCode::SUCCESS, $this->runner()->run($attempt->id, 1, 'n'));

        $result = Job::find($job->id)->result;
        $this->assertSame((string) $batch->id, $result['batch_id']);
        $this->assertSame('nightly-sync', $result['batch']['name']);
        $this->assertSame('continue', $result['batch']['failure_policy']);
        $this->assertSame(1, $result['batch']['counts']['running'], 'the member reads its batch mid-run');
    }

    public function test_a_standalone_job_has_no_batch_in_its_context(): void
    {
        [$job, $attempt] = $this->dispatched(BatchAwareJob::class, [], idempotent: true);

        $this->assertSame(ExitCode::SUCCESS, $this->runner()->run($attempt->id, 1, 'n'));

        $result = Job::find($job->id)->result;
        $this->assertNull($result['batch_id']);
        $this->assertNull($result['batch']);
    }

    public function test_result_set_by_the_handler_commits_with_success(): void
    {
        [$job, $attempt] = $this->dispatched(ResultJob::class, [
            'result' => ['imported' => 42, 'skipped' => ['a', 'b']],
        ], idempotent: true);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::SUCCESS, $code);
        $job = Job::find($job->id);
        $this->assertSame(JobState::Succeeded, $job->state);
        $this->assertSame(['imported' => 42, 'skipped' => ['a', 'b']], $job->result);
    }

    public function test_result_is_discarded_when_the_handler_fails_after_setting_it(): void
    {
        [$job, $attempt] = $this->dispatched(ResultJob::class, [
            'result' => ['partial' => true],
            'then_fail' => true,
        ], idempotent: false, maxAttempts: 1);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $job = Job::find($job->id);
        $this->assertSame(JobState::Failed, $job->state);
        $this->assertNull($job->result, 'result is success-only; a failed run must not leave one');
    }

    public function test_oversized_result_fails_the_run_at_the_call_site(): void
    {
        config(['jobwarden.results.max_bytes' => 1024]);
        [$job, $attempt] = $this->dispatched(ResultJob::class, ['fill_bytes' => 2048], idempotent: false, maxAttempts: 1);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $this->assertSame(JobState::Failed, Job::find($job->id)->state);
        $this->assertNull(Job::find($job->id)->result);
        $this->assertStringContainsString('max_bytes', JobAttempt::find($attempt->id)->error['message']);
    }

    public function test_fenced_out_child_cannot_land_its_result(): void
    {
        // The handler simulates a reaper takeover mid-run (fencing token bumped
        // in the DB). succeed()'s guarded transition misses, and the WHOLE
        // commit — including the buffered result — rolls back.
        [$job, $attempt] = $this->dispatched(ResultJob::class, [
            'result' => ['stale' => 'must not land'],
            'fence_out' => true,
        ], idempotent: true);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        // The child exits quietly on a fence-out; the new owner holds the outcome.
        $this->assertSame(ExitCode::SUCCESS, $code);
        $job = Job::find($job->id);
        $this->assertNull($job->result, 'a fenced-out result must roll back with the refused transition');
        $this->assertSame(JobState::Running, $job->state, 'authoritative state is left for the new owner');
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

    /**
     * REGRESSION (field report, prefork cutover): the child logs through whatever
     * `logging.default` names. In exec mode the console command swapped that for a
     * job_logs-only channel; a prefork child never runs the command, so it kept
     * logging through the HOST application's channel — inherited across the fork and
     * pointed at handlers a forked child has no business writing to. When one of them
     * threw, it unwound the failure report and the attempt kept error=NULL, which is
     * exactly the condition that makes the supervisor synthesize a ProcessDied and
     * bury the real exception.
     *
     * The child must never touch the host's channel, and the error artifact must
     * survive a logging stack that throws either way.
     */
    public function test_the_host_applications_log_channel_cannot_bury_the_failure(): void
    {
        config([
            'logging.default' => 'host_app',
            'logging.channels.host_app' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                // Unopenable (a closed fd 2 in the field): Monolog throws on first write.
                'with' => ['stream' => '/dev/null/not-a-directory'],
            ],
        ]);

        [$job, $attempt] = $this->dispatched(FailingJob::class, ['message' => 'the real error'], idempotent: false, maxAttempts: 1);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $this->assertSame('the real error', JobAttempt::find($attempt->id)->error['message'], 'the diagnosable error was lost to the logging stack');
        $this->assertSame('the real error', Job::find($job->id)->last_error['message']);
        $this->assertSame(AttemptState::Failed, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Failed, Job::find($job->id)->state, 'the child must report its own outcome, not leave it for the supervisor to force');
    }

    /** The success path has the same exposure, with a worse ending: a job that really
     *  ran would be left non-terminal for the supervisor to force-FAIL. */
    public function test_the_host_applications_log_channel_cannot_bury_a_success(): void
    {
        config([
            'logging.default' => 'host_app',
            'logging.channels.host_app' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => '/dev/null/not-a-directory'],
            ],
        ]);

        $marker = $this->runtime.'/survived.txt';
        [$job, $attempt] = $this->dispatched(MarkerJob::class, ['marker' => $marker], idempotent: true);

        $code = $this->runner()->run($attempt->id, 1, 'nonce-abc');

        $this->assertSame(ExitCode::SUCCESS, $code);
        $this->assertSame(AttemptState::Succeeded, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Succeeded, Job::find($job->id)->state, 'a job that did its work was left for the supervisor to force-fail');
        $this->assertStringStartsWith('done:', (string) @file_get_contents($marker));
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

    public function test_constructor_params_bind_by_name_and_services_inject_into_handle(): void
    {
        $marker = $this->runtime.'/typed.json';
        [$job, $attempt] = $this->dispatched(TypedParamsJob::class, [
            'marker' => $marker,
            'mode' => 'full',                       // → ImportMode::Full
            'limit' => 25,
            'asOf' => '2026-07-01T09:00:00Z',       // → CarbonImmutable
            'extra' => 'not-a-constructor-param',   // ignored by binding (ride-along metadata)
        ], idempotent: true);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::SUCCESS, $code);
        $this->assertSame(JobState::Succeeded, Job::find($job->id)->state);

        $seen = json_decode((string) file_get_contents($marker), true);
        $this->assertSame('full', $seen['mode']);
        $this->assertSame(25, $seen['limit']);
        $this->assertSame('2026-07-01T09:00:00+00:00', $seen['as_of']);
        $this->assertTrue($seen['service_resolved'], 'handle() is container-invoked: service params method-inject');
        $this->assertSame(1, $seen['attempt'], 'JobContext still carries execution identity');
    }

    public function test_missing_required_constructor_param_fails_the_attempt_loud(): void
    {
        // `mode` (a backed enum: data, no default) is absent → the handler never
        // runs and the attempt fails with the binding error recorded.
        [$job, $attempt] = $this->dispatched(TypedParamsJob::class, [
            'marker' => $this->runtime.'/never-written.json',
        ], idempotent: false, maxAttempts: 1);

        $code = $this->runner()->run($attempt->id, 1, 'n');

        $this->assertSame(ExitCode::FAILURE, $code);
        $this->assertSame(JobState::Failed, Job::find($job->id)->state);
        $this->assertStringContainsString('$mode', JobAttempt::find($attempt->id)->error['message']);
        $this->assertFileDoesNotExist($this->runtime.'/never-written.json');
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
    private function dispatched(string $jobClass, array $params, bool $idempotent, int $maxAttempts = 1, ?string $batchId = null): array
    {
        $job = Job::create([
            'job_class' => $jobClass,
            'batch_id' => $batchId,
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
