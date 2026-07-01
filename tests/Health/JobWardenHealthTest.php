<?php

declare(strict_types=1);

namespace JobWarden\Tests\Health;

use JobWarden\Health\JobWardenHealth;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The invariant tripwire itself: it must catch the aggregate-integrity
 * violations this engine exists to prevent, and stay silent on a clean store.
 */
final class JobWardenHealthTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function health(): JobWardenHealth
    {
        return $this->app->make(JobWardenHealth::class);
    }

    public function test_a_clean_store_reports_no_violations(): void
    {
        $job = Job::create([
            'job_class' => 'X', 'state' => JobState::Succeeded, 'idempotent' => true,
            'max_attempts' => 3, 'attempt_count' => 1, 'backoff_strategy' => 'fixed',
        ]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Succeeded,
            'fencing_token' => 1, 'host_id' => 'h',
        ]);
        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        $this->assertTrue($this->health()->isConsistent());
    }

    public function test_it_flags_a_job_pointing_at_a_missing_attempt(): void
    {
        Job::create([
            'job_class' => 'X', 'state' => JobState::Running, 'idempotent' => true,
            'max_attempts' => 3, 'attempt_count' => 1, 'backoff_strategy' => 'fixed',
            'current_attempt_id' => (string) Uuid::v7(), // dangling — no such attempt
        ]);

        $violations = $this->health()->invariantViolations();

        $this->assertContains('job_current_attempt_missing', array_column($violations, 'invariant'));
    }

    public function test_it_flags_attempt_count_below_the_highest_attempt_number(): void
    {
        $job = Job::create([
            'job_class' => 'X', 'state' => JobState::Running, 'idempotent' => true,
            'max_attempts' => 3, 'attempt_count' => 1, 'backoff_strategy' => 'fixed', // stale count
        ]);
        foreach ([1, 2] as $n) {
            JobAttempt::create([
                'job_id' => $job->id, 'attempt_number' => $n, 'state' => AttemptState::Failed,
                'fencing_token' => $n, 'host_id' => 'h',
            ]);
        }

        $violations = $this->health()->invariantViolations();

        $this->assertContains('attempt_count_below_max_number', array_column($violations, 'invariant'));
    }
}
