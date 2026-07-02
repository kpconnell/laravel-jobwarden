<?php

declare(strict_types=1);

namespace JobWarden\Tests\Recovery;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Operations\OperatorActions;
use JobWarden\Recovery\RecoveryService;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class RecoveryAndOperatorTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function ops(): OperatorActions
    {
        return $this->app->make(OperatorActions::class);
    }

    private function recovery(): RecoveryService
    {
        return $this->app->make(RecoveryService::class);
    }

    // -- the binary idempotency guard on orphan recovery ------------------

    public function test_idempotent_orphan_retries(): void
    {
        [$job] = $this->seedOrphaned(idempotent: true);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);
    }

    public function test_idempotent_orphan_with_exhausted_budget_fails(): void
    {
        // An idempotent job orphaned on its FINAL attempt (budget spent) is
        // determinate — it must fail, not park in `orphaned` limbo. Spec §3.6:
        // `orphaned → failed` when "idempotent = false OR budget exhausted".
        // (Regression: a full-stack restart that orphaned throw/crash jobs on
        // attempt max_attempts left them stranded in `orphaned` forever.)
        [$job] = $this->seedOrphaned(idempotent: true, attemptCount: 3); // == max_attempts

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $this->assertSame(JobState::Failed, Job::find($job->id)->state);
    }

    public function test_non_idempotent_orphan_parks_by_default(): void
    {
        [$job] = $this->seedOrphaned(idempotent: false);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        // Indeterminate → parked in orphaned for an operator. No third path.
        $this->assertSame(JobState::Orphaned, Job::find($job->id)->state);
    }

    public function test_non_idempotent_orphan_auto_fails_when_configured(): void
    {
        config(['jobwarden.retry.non_idempotent_orphan_policy' => 'auto_fail']);
        [$job] = $this->seedOrphaned(idempotent: false);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $this->assertSame(JobState::Failed, Job::find($job->id)->state);
    }

    // -- cancellation desired-state ---------------------------------------

    public function test_cancel_of_a_pre_run_job_cancels_immediately(): void
    {
        $job = $this->seedJob(JobState::Queued);

        $this->ops()->cancel($job, 'no longer needed', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Canceled, $job->state);
        $this->assertTrue($job->cancel_requested);
        $this->assertSame('cancel', $job->cancel_mode);
    }

    public function test_cancel_of_a_running_job_only_flags_it_for_the_supervisor(): void
    {
        $job = $this->seedJob(JobState::Running);

        $this->ops()->cancel($job, 'stop it', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Running, $job->state, 'a running job is not transitioned directly');
        $this->assertTrue($job->cancel_requested);
    }

    public function test_cancellation_is_honored_over_retry_on_orphan_recovery(): void
    {
        [$job] = $this->seedOrphaned(idempotent: true); // would normally retry
        Job::where('id', $job->id)->update(['cancel_requested' => true, 'cancel_mode' => 'stop', 'cancel_reason' => 'operator stop']);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $this->assertSame(JobState::Stopped, Job::find($job->id)->state);
    }

    // -- operator overrides ------------------------------------------------

    public function test_retry_re_queues_a_failed_job(): void
    {
        $job = $this->seedJob(JobState::Failed);

        $this->ops()->retry($job, 'transient downstream issue', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Queued, $job->state);
        $this->assertTrue($job->available_at->lessThanOrEqualTo(now()));
    }

    public function test_restart_re_queues_a_parked_orphan(): void
    {
        [$job] = $this->seedOrphaned(idempotent: false);

        $this->ops()->restart($job, 'verified safe by operator', 'op-1');

        $this->assertSame(JobState::Queued, Job::find($job->id)->state);
    }

    public function test_restart_re_queues_a_stopped_job(): void
    {
        $job = $this->seedJob(JobState::Stopped);
        $job->forceFill(['cancel_requested' => true, 'cancel_mode' => 'stop'])->save();

        $this->ops()->restart($job, 'run it again', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Queued, $job->state);
        $this->assertFalse((bool) $job->cancel_requested);
        $this->assertNull($job->cancel_mode);
    }

    // -- helpers -----------------------------------------------------------

    private function seedJob(JobState $state, bool $idempotent = true): Job
    {
        return Job::create([
            'job_class' => 'X',
            'state' => $state,
            'idempotent' => $idempotent,
            'max_attempts' => 3,
            'attempt_count' => 1,
            'available_at' => now()->subSecond(),
        ]);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function seedOrphaned(bool $idempotent, int $attemptCount = 1): array
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Orphaned,
            'idempotent' => $idempotent,
            'max_attempts' => 3,
            'attempt_count' => $attemptCount,
            'backoff_strategy' => 'fixed',
        ]);

        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Orphaned,
            'fencing_token' => 2,
        ]);

        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        return [$job, $attempt];
    }
}
