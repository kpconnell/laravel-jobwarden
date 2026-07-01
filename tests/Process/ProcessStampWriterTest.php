<?php

declare(strict_types=1);

namespace JobWarden\Tests\Process;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class ProcessStampWriterTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_phase2_completes_the_child_stamp_under_the_current_token(): void
    {
        [$attempt] = $this->dispatchedAttempt(token: 1);
        $writer = $this->app->make(ProcessStampWriter::class);

        $ok = $writer->phase2($attempt->id, 1, childPid: 4242, childStartTime: '8675309', nonce: 'abc');

        $this->assertTrue($ok);
        $fresh = JobAttempt::find($attempt->id);
        $this->assertSame(4242, $fresh->child_pid);
        $this->assertSame(8675309, $fresh->child_start_time);
        $this->assertSame('abc', $fresh->proc_nonce);
    }

    public function test_phase2_is_rejected_when_the_epoch_has_moved_on(): void
    {
        [$attempt] = $this->dispatchedAttempt(token: 1);
        $writer = $this->app->make(ProcessStampWriter::class);

        // A late phase-2 write carrying a stale token must not land.
        $ok = $writer->phase2($attempt->id, 99, childPid: 1, childStartTime: '1', nonce: 'x');

        $this->assertFalse($ok);
        $this->assertNull(JobAttempt::find($attempt->id)->child_pid);
    }

    /** @return array{0: JobAttempt} */
    private function dispatchedAttempt(int $token): array
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running, 'attempt_count' => 1]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Dispatched,
            'fencing_token' => $token,
        ]);

        return [$attempt];
    }
}
