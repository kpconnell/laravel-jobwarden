<?php

declare(strict_types=1);

namespace JobWarden\Tests\Recovery;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobEvent;
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

    public function test_cancel_is_refused_on_a_running_job(): void
    {
        // Each halt verb is scoped to the states it names: cancel withdraws work
        // that has not started, stop halts active work. Neither is silently
        // mapped to the other.
        $job = $this->seedJob(JobState::Running);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot cancel a running job');

        $this->ops()->cancel($job, 'stop it', 'op-1');
    }

    public function test_stop_is_refused_on_a_pre_run_job(): void
    {
        $job = $this->seedJob(JobState::Queued);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot stop a queued job');

        $this->ops()->stop($job, 'stop it', 'op-1');
    }

    public function test_stop_of_a_running_job_only_flags_it_for_the_supervisor(): void
    {
        $job = $this->seedJob(JobState::Running);

        $this->ops()->stop($job, 'stop it', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Running, $job->state, 'a running job is not transitioned directly');
        $this->assertTrue($job->cancel_requested);
        $this->assertSame('stop', $job->cancel_mode);
    }

    public function test_stop_of_a_parked_orphan_stops_it_immediately(): void
    {
        [$job] = $this->seedOrphaned(idempotent: false);

        $this->ops()->stop($job, 'give up', 'op-1');

        $this->assertSame(JobState::Stopped, Job::find($job->id)->state);
    }

    public function test_cancellation_is_honored_over_retry_on_orphan_recovery(): void
    {
        [$job] = $this->seedOrphaned(idempotent: true); // would normally retry
        Job::where('id', $job->id)->update(['cancel_requested' => true, 'cancel_mode' => 'stop', 'cancel_reason' => 'operator stop']);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $this->assertSame(JobState::Stopped, Job::find($job->id)->state);
    }

    // -- skip: the operator's verdict that the outcome does not matter --------

    public function test_skip_moves_a_settled_job_directly_and_keeps_its_error(): void
    {
        foreach ([JobState::Failed, JobState::Stopped, JobState::Canceled] as $from) {
            $job = $this->seedJob($from);
            $job->forceFill(['last_error' => ['class' => 'RuntimeException', 'message' => 'boom']])->saveQuietly();

            $this->ops()->skip($job, 'not mission critical tonight', 'op-1');

            $job = Job::find($job->id);
            $this->assertSame(JobState::Skipped, $job->state, "from {$from->value}");
            $this->assertFalse((bool) $job->cancel_requested, 'a settled job takes no desired-state flag');
            $this->assertSame('boom', $job->last_error['message'], 'the verdict overrides the error, it does not erase it');

            $event = JobEvent::query()->where('job_id', $job->id)->orderByDesc('id')->first();
            $this->assertSame('skipped', $event->to_state);
            $this->assertSame(ActorType::Operator, $event->actor_type);
            $this->assertSame('op-1', $event->actor_id);
            $this->assertSame('not mission critical tonight', $event->reason);
        }
    }

    public function test_skip_of_a_pre_run_job_skips_it_immediately_with_the_flag_set(): void
    {
        foreach ([JobState::Pending, JobState::Queued, JobState::Retrying] as $from) {
            $job = $this->seedJob($from);

            $this->ops()->skip($job, 'step not needed this run', 'op-1');

            $job = Job::find($job->id);
            $this->assertSame(JobState::Skipped, $job->state, "from {$from->value}");
            // The flag is written first so a claim that wins the race is still
            // halted by its supervisor — and lands skipped, per the mode.
            $this->assertTrue((bool) $job->cancel_requested);
            $this->assertSame('skip', $job->cancel_mode);
        }
    }

    public function test_skip_of_a_running_job_is_kill_and_skip_via_the_supervisor(): void
    {
        $job = $this->seedJob(JobState::Running);

        $this->ops()->skip($job, 'kill it, move on', 'op-1');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Running, $job->state, 'the operator never lands a running job; its supervisor does');
        $this->assertTrue((bool) $job->cancel_requested);
        $this->assertSame('skip', $job->cancel_mode);
        $this->assertSame('kill it, move on', $job->cancel_reason);
    }

    public function test_skip_of_a_parked_orphan_skips_it_immediately(): void
    {
        [$job] = $this->seedOrphaned(idempotent: false);

        $this->ops()->skip($job, 'outcome unknown and irrelevant', 'op-1');

        $this->assertSame(JobState::Skipped, Job::find($job->id)->state);
    }

    public function test_skip_is_refused_on_a_succeeded_job(): void
    {
        $job = $this->seedJob(JobState::Succeeded);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot skip a succeeded job');

        $this->ops()->skip($job, 'nope', 'op-1');
    }

    public function test_a_skip_flag_is_honored_as_skipped_on_orphan_recovery(): void
    {
        [$job] = $this->seedOrphaned(idempotent: true); // would normally retry
        Job::where('id', $job->id)->update(['cancel_requested' => true, 'cancel_mode' => 'skip', 'cancel_reason' => 'kill and skip']);

        $this->recovery()->resolveOrphan(Job::find($job->id), ActorType::Reaper, 'host dead');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Skipped, $job->state, 'the mode picks the landing state');
        $event = JobEvent::query()->where('job_id', $job->id)->orderByDesc('id')->first();
        $this->assertSame('skipped honored on recovery: kill and skip', $event->reason);
    }

    public function test_a_skip_flag_is_honored_as_skipped_when_the_attempt_fails_first(): void
    {
        // The child failed on its own before the SIGTERM landed: the verdict is
        // still skip, never a retry.
        $job = $this->seedJob(JobState::Running, idempotent: true);
        Job::where('id', $job->id)->update(['cancel_requested' => true, 'cancel_mode' => 'skip', 'cancel_reason' => 'kill and skip']);

        $this->recovery()->afterAttemptFailure(Job::find($job->id), ActorType::Supervisor, 'child died');

        $this->assertSame(JobState::Skipped, Job::find($job->id)->state);
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
