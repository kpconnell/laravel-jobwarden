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

final class JobLoggerScalabilityTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_high_volume_writes_and_fresh_writer_handoff_keep_gapless_attempt_sequence(): void
    {
        [$job, $attempt] = $this->seedRunning();
        $logger = $this->app->make(JobLogger::class);

        for ($i = 1; $i <= 500; $i++) {
            $logger->write($job->id, $attempt->id, 'info', "child line {$i}", ['i' => $i], 'child');
        }

        // Model a separate process, such as a reaper, injecting follow-up rows
        // after the child writer has already emitted a large live log stream.
        $freshWriter = new JobLogger(new DatabaseSink);
        for ($i = 1; $i <= 25; $i++) {
            $freshWriter->write($job->id, $attempt->id, 'warning', "reaper line {$i}", ['i' => $i], 'reaper');
        }

        $seqs = JobLog::where('attempt_id', $attempt->id)->orderBy('seq')->pluck('seq')->all();

        $this->assertCount(525, $seqs);
        $this->assertSame(range(1, 525), $seqs, 'per-attempt log seq must be gapless and monotonic');
        $this->assertSame(525, count(array_unique($seqs)));
        $this->assertSame('child line 1', JobLog::where('attempt_id', $attempt->id)->where('seq', 1)->value('body_ref'));
        $this->assertSame('reaper line 25', JobLog::where('attempt_id', $attempt->id)->where('seq', 525)->value('body_ref'));
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
