<?php

declare(strict_types=1);

namespace JobWarden\Tests\Logging;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Runner\ChildRunner;
use JobWarden\Runner\ExitCode;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Workbench\App\Jobs\ChattyJob;

final class JobLogCaptureTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobwarden.runtime_path' => sys_get_temp_dir().'/jobwarden-log-'.bin2hex(random_bytes(4))]);
        $this->setUpJobWardenSchema();
    }

    public function test_log_facade_calls_and_worker_wrappers_land_in_job_logs(): void
    {
        $job = Job::create([
            'job_class' => ChattyJob::class,
            'state' => JobState::Running,
            'params' => ['steps' => 2],
            'idempotent' => true,
            'attempt_count' => 1,
        ]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Dispatched,
            'fencing_token' => 1,
        ]);

        $code = $this->app->make(ChildRunner::class)->run($attempt->id, 1, 'nonce');
        $this->assertSame(ExitCode::SUCCESS, $code);

        $logs = JobLog::where('attempt_id', $attempt->id)->orderBy('seq')->get();
        $bodies = $logs->pluck('body_ref')->all();
        $steps = $logs->pluck('step')->filter()->values()->all();

        // Worker wrappers (via the standard Log facade) bracket the run.
        $this->assertStringContainsString('Starting job', $bodies[0]);
        $this->assertStringContainsString('Job finished', end($bodies));
        $this->assertContains('starting', $steps);
        $this->assertContains('finished', $steps);

        // The handler's own Log::info() / Log::notice() calls were captured.
        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('working step 1/2', $joined);
        $this->assertStringContainsString('working step 2/2', $joined);
        $this->assertStringContainsString('all steps complete', $joined);

        // Monotonic seq, contiguous from 1.
        $this->assertSame(range(1, $logs->count()), $logs->pluck('seq')->all());
    }
}
