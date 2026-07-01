<?php

declare(strict_types=1);

namespace JobWarden\Tests\StateMachine;

use JobWarden\Events\JobStateChanged;
use JobWarden\Exceptions\DirectStateMutationException;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class StateMachineTest extends TestCase
{
    use RefreshesJobWardenSchema;

    private StateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        $this->sm = $this->app->make(StateMachine::class);
    }

    public function test_legal_transition_moves_state_writes_one_event_and_fires_one_post_commit_event(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        Event::fake([JobStateChanged::class]);

        $result = $this->sm->applyJobTransition(
            $job,
            JobState::Running,
            TransitionContext::for(ActorType::Worker, 'worker-1', 'claimed')
        );

        $this->assertSame(JobState::Running, Job::find($job->id)->state);
        $this->assertSame(JobState::Running, $job->state); // in-memory reflects it

        $events = $this->jobwarden()->table($this->tbl('job_events'))->where('job_id', $job->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('queued', $events[0]->from_state);
        $this->assertSame('running', $events[0]->to_state);
        $this->assertSame('worker', $events[0]->actor_type);
        $this->assertSame('job', $events[0]->level);
        $this->assertSame($result->eventId, (int) $events[0]->id);

        Event::assertDispatchedTimes(JobStateChanged::class, 1);
    }

    public function test_illegal_transition_throws_and_writes_nothing(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        try {
            $this->sm->applyJobTransition($job, JobState::Succeeded, TransitionContext::for(ActorType::Worker));
            $this->fail('expected IllegalTransitionException');
        } catch (IllegalTransitionException $e) {
            // queued → succeeded is not an edge.
        }

        $this->assertSame(JobState::Queued, Job::find($job->id)->state);
        $this->assertSame(0, $this->jobwarden()->table($this->tbl('job_events'))->count());
    }

    public function test_wrong_actor_is_rejected(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $this->expectException(IllegalTransitionException::class);
        // queued → canceled is operator-only.
        $this->sm->applyJobTransition($job, JobState::Canceled, TransitionContext::for(ActorType::Worker));
    }

    public function test_decided_input_guard_blocks_non_idempotent_retry(): void
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => false,
            'max_attempts' => 3,
            'attempt_count' => 1,
        ]);

        $this->expectException(GuardFailedException::class);
        $this->sm->applyJobTransition($job, JobState::Retrying, TransitionContext::for(ActorType::Reaper));
    }

    public function test_idempotent_retry_is_allowed_within_budget(): void
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => true,
            'max_attempts' => 3,
            'attempt_count' => 1,
        ]);

        $this->sm->applyJobTransition($job, JobState::Retrying, TransitionContext::for(ActorType::Reaper));

        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);
    }

    public function test_attempt_terminal_report_requires_the_fencing_token(): void
    {
        [$job, $attempt] = $this->runningAttempt();

        $this->expectException(\LogicException::class);
        // No expectingToken() → programming error, refused before any write.
        $this->sm->applyAttemptTransition($attempt, AttemptState::Succeeded, TransitionContext::for(ActorType::Worker));
    }

    public function test_orphan_bumps_the_fencing_token_by_exactly_one(): void
    {
        [$job, $attempt] = $this->runningAttempt();
        $this->assertSame(1, $attempt->fencing_token);

        $this->sm->applyAttemptTransition(
            $attempt,
            AttemptState::Orphaned,
            TransitionContext::for(ActorType::Reaper)->bumpingFence()
        );

        $this->assertSame(AttemptState::Orphaned, JobAttempt::find($attempt->id)->state);
        $this->assertSame(2, JobAttempt::find($attempt->id)->fencing_token);
        $this->assertSame(2, $attempt->fencing_token); // in-memory updated
    }

    public function test_stale_fencing_token_write_is_rejected_and_recorded_not_clobbered(): void
    {
        [$job, $attempt] = $this->runningAttempt();

        // A zombie worker's stale view: still 'running' at epoch 1.
        $zombie = JobAttempt::find($attempt->id);

        // The reaper orphans the attempt, bumping the epoch to 2.
        $this->sm->applyAttemptTransition(
            $attempt,
            AttemptState::Orphaned,
            TransitionContext::for(ActorType::Reaper)->bumpingFence()
        );

        // The zombie wakes and tries to report success carrying epoch 1.
        try {
            $this->sm->applyAttemptTransition(
                $zombie,
                AttemptState::Succeeded,
                TransitionContext::for(ActorType::Worker)->expectingToken(1)
            );
            $this->fail('expected StaleFencingTokenException');
        } catch (StaleFencingTokenException $e) {
            $this->assertSame(1, $e->expectedFencingToken);
        }

        // State was NOT clobbered: still orphaned at epoch 2.
        $fresh = JobAttempt::find($attempt->id);
        $this->assertSame(AttemptState::Orphaned, $fresh->state);
        $this->assertSame(2, $fresh->fencing_token);

        // The rejection was recorded as a reconciliation event.
        $reconciliation = $this->jobwarden()->table($this->tbl('job_events'))
            ->where('attempt_id', $attempt->id)
            ->where('reason', 'like', 'stale_write_rejected%')
            ->first();
        $this->assertNotNull($reconciliation);
        $this->assertSame('system', $reconciliation->actor_type);
    }

    public function test_state_guarded_trait_blocks_direct_model_state_writes(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $this->expectException(DirectStateMutationException::class);
        $job->state = JobState::Running;
        $job->save();
    }

    public function test_transaction_rolls_back_leaving_no_event_when_audit_write_fails(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        // Invalid UTF-8 forces json_encode to throw AFTER the guarded UPDATE,
        // exercising the rollback path.
        $threw = false;
        try {
            $this->sm->applyJobTransition(
                $job,
                JobState::Running,
                TransitionContext::for(ActorType::Worker)->withContext(['bad' => "\xB1\x31"])
            );
        } catch (\JsonException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'expected a JsonException to abort the transition');
        $this->assertSame(JobState::Queued, Job::find($job->id)->state, 'state must have rolled back');
        $this->assertSame(0, $this->jobwarden()->table($this->tbl('job_events'))->where('job_id', $job->id)->count());
    }

    public function test_batch_counters_partition_is_maintained_in_transaction(): void
    {
        $batch = Batch::create([
            'state' => BatchState::Running,
            'total_jobs' => 2,
            'pending_count' => 2,
        ]);

        $j1 = Job::create(['job_class' => 'X', 'state' => JobState::Pending, 'batch_id' => $batch->id]);
        $j2 = Job::create(['job_class' => 'X', 'state' => JobState::Pending, 'batch_id' => $batch->id]);

        $sys = fn () => TransitionContext::for(ActorType::System);
        $worker = fn () => TransitionContext::for(ActorType::Worker);

        $this->sm->applyJobTransition($j1, JobState::Queued, $sys());     // same bucket (pending)
        $this->sm->applyJobTransition($j1, JobState::Running, $worker()); // pending → running
        $this->sm->applyJobTransition($j1, JobState::Succeeded, $worker());

        $this->sm->applyJobTransition($j2, JobState::Queued, $sys());
        $this->sm->applyJobTransition($j2, JobState::Running, $worker());
        $this->sm->applyJobTransition($j2, JobState::Failed, $worker());

        $batch = Batch::find($batch->id);
        $this->assertSame(0, $batch->pending_count);
        $this->assertSame(0, $batch->running_count);
        $this->assertSame(1, $batch->succeeded_count);
        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(0, $batch->canceled_count);

        // The partition invariant: buckets always sum to total_jobs.
        $sum = $batch->pending_count + $batch->running_count + $batch->succeeded_count
            + $batch->failed_count + $batch->canceled_count;
        $this->assertSame($batch->total_jobs, $sum);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function runningAttempt(): array
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
