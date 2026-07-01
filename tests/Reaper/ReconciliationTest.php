<?php

declare(strict_types=1);

namespace JobWarden\Tests\Reaper;

use JobWarden\Health\JobWardenHealth;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Reaper\GlobalReaper;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * The aggregate-invariant backstop (Tier 3 reconciliation). A process dying in
 * the microsecond window between the attempt transition and the job transition
 * strands a job in `running` with an already-settled current attempt. Fencing
 * prevents any double-run; the leader reaper's sweep heals the stranded state.
 * These seed the stranded shapes directly (no child spawning), so they run on
 * every engine including SQLite.
 */
final class ReconciliationTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // Tight grace so a backdated settlement is always "old enough" to sweep.
        config(['jobwarden.reaper.reconcile_grace_sec' => 5]);
        $this->setUpJobWardenSchema();
    }

    private function reaper(): GlobalReaper
    {
        return $this->app->make(GlobalReaper::class);
    }

    private function health(): JobWardenHealth
    {
        return $this->app->make(JobWardenHealth::class);
    }

    public function test_a_stranded_succeeded_attempt_completes_the_job(): void
    {
        [$job] = $this->seedStranded(AttemptState::Succeeded);

        // The invariant tripwire sees the inconsistency before we heal it.
        $this->assertNotSame([], $this->health()->invariantViolations());

        $this->assertTrue($this->reaper()->tick('reaper-x'));

        $this->assertSame(JobState::Succeeded, Job::find($job->id)->state);
        $this->assertSame([], $this->health()->invariantViolations(), 'store is consistent again');
    }

    public function test_a_stranded_failed_idempotent_attempt_is_retried(): void
    {
        [$job] = $this->seedStranded(AttemptState::Failed, idempotent: true, maxAttempts: 3, attemptCount: 1);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(JobState::Retrying, Job::find($job->id)->state, 'idempotent with budget → retry');
        $this->assertSame([], $this->health()->invariantViolations());
    }

    public function test_a_stranded_failed_exhausted_attempt_fails_the_job(): void
    {
        [$job] = $this->seedStranded(AttemptState::Failed, idempotent: true, maxAttempts: 1, attemptCount: 1);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(JobState::Failed, Job::find($job->id)->state, 'budget exhausted → failed');
    }

    public function test_a_stranded_orphaned_idempotent_attempt_is_recovered(): void
    {
        [$job] = $this->seedStranded(AttemptState::Orphaned, idempotent: true, maxAttempts: 3, attemptCount: 1);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);
        $this->assertSame([], $this->health()->invariantViolations());
    }

    public function test_a_stranded_stopped_attempt_stops_the_job(): void
    {
        [$job] = $this->seedStranded(AttemptState::Stopped);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(JobState::Stopped, Job::find($job->id)->state);
    }

    public function test_the_grace_window_defers_a_freshly_settled_job(): void
    {
        // A worker that just committed its attempt is racing to commit the job; the
        // grace window must not let the sweep steal that completion from under it.
        config(['jobwarden.reaper.reconcile_grace_sec' => 30]);
        [$job] = $this->seedStranded(AttemptState::Succeeded, settledSecondsAgo: 2);

        $this->reaper()->tick('reaper-x');
        $this->assertSame(JobState::Running, Job::find($job->id)->state, 'within grace: left for the worker');

        // Once the settlement is genuinely old, it is reconciled.
        config(['jobwarden.reaper.reconcile_grace_sec' => 1]);
        $this->reaper()->tick('reaper-x');
        $this->assertSame(JobState::Succeeded, Job::find($job->id)->state, 'past grace: reconciled');
    }

    public function test_a_healthy_in_flight_job_is_left_alone(): void
    {
        $job = Job::create([
            'job_class' => 'X', 'state' => JobState::Running, 'idempotent' => true,
            'max_attempts' => 3, 'attempt_count' => 1, 'backoff_strategy' => 'fixed',
        ]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Running,
            'fencing_token' => 1, 'host_id' => 'host-x', 'child_pid' => 9999, 'child_start_time' => 123,
        ]);
        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        $this->assertSame([], $this->health()->invariantViolations(), 'running + running attempt is consistent');

        $this->reaper()->tick('reaper-x');

        $this->assertSame(JobState::Running, Job::find($job->id)->state);
        $this->assertSame(AttemptState::Running, JobAttempt::find($attempt->id)->state);
    }

    /**
     * @return array{0: Job, 1: JobAttempt}
     */
    private function seedStranded(
        AttemptState $attemptState,
        bool $idempotent = true,
        int $maxAttempts = 3,
        int $attemptCount = 1,
        int $token = 1,
        int $settledSecondsAgo = 120,
    ): array {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => $idempotent,
            'max_attempts' => $maxAttempts,
            'attempt_count' => $attemptCount,
            'backoff_strategy' => 'fixed',
        ]);

        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => $attemptCount,
            'state' => $attemptState,
            'fencing_token' => $token,
            'host_id' => 'host-x',
            'child_pid' => 9999,
            'child_start_time' => 123,
        ]);

        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        // Backdate the settlement so it falls outside the grace window. finished_at
        // is only set for the truly-terminal states (orphaned bumps updated_at).
        $past = Carbon::now()->subSeconds($settledSecondsAgo);
        JobAttempt::where('id', $attempt->id)->update([
            'finished_at' => $attemptState === AttemptState::Orphaned ? null : $past,
            'updated_at' => $past,
        ]);

        return [$job->fresh(), $attempt->fresh()];
    }
}
