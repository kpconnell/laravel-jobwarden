<?php

declare(strict_types=1);

namespace JobWarden\Tests\Logging;

use JobWarden\Logging\JobLogger;
use JobWarden\Logging\Sinks\DatabaseSink;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class JobLoggerTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_writes_indexed_rows_with_monotonic_seq(): void
    {
        [$job, $attempt] = $this->seedRunning();
        $logger = $this->app->make(JobLogger::class);

        $logger->write($job->id, $attempt->id, 'info', 'one', ['k' => 'v', 'step' => 'start'], 'start');
        $logger->write($job->id, $attempt->id, 'warning', 'two');

        $rows = JobLog::where('attempt_id', $attempt->id)->orderBy('seq')->get();
        $this->assertCount(2, $rows);

        $this->assertSame([1, 2], $rows->pluck('seq')->all());
        $this->assertSame('info', $rows[0]->level);
        $this->assertSame('one', $rows[0]->body_ref);
        $this->assertSame('start', $rows[0]->step);
        $this->assertSame('database', $rows[0]->body_sink);
        // step is promoted to its column, not duplicated in context.
        $this->assertSame(['k' => 'v'], $rows[0]->context);
        $this->assertSame('two', $rows[1]->body_ref);
        $this->assertNull($rows[1]->context);
    }

    public function test_a_separate_process_continues_seq_from_the_db_the_reaper_seam(): void
    {
        [$job, $attempt] = $this->seedRunning();

        $this->app->make(JobLogger::class)->write($job->id, $attempt->id, 'info', 'child line');

        // A fresh JobLogger models a different process (e.g. the reaper) writing
        // an injected entry that explains its action.
        $reaperLogger = new JobLogger(new DatabaseSink);
        $reaperLogger->write($job->id, $attempt->id, 'warning', 'local reaper orphaned this attempt', ['actor' => 'reaper'], 'reaped');

        $rows = JobLog::where('attempt_id', $attempt->id)->orderBy('seq')->get();
        $this->assertSame([1, 2], $rows->pluck('seq')->all());
        $this->assertSame('reaped', $rows[1]->step);
        $this->assertSame(['actor' => 'reaper'], $rows[1]->context);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function seedRunning(): array
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running, 'attempt_count' => 1]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Running,
            'fencing_token' => 1,
        ]);

        return [$job, $attempt];
    }
}
