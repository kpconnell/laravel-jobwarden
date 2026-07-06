<?php

declare(strict_types=1);

namespace JobWarden\Tests\Batch;

use JobWarden\Batch\BatchCoordinator;
use JobWarden\Events\JobStateChanged;
use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Reaper\GlobalReaper;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

/**
 * The batch event-loss backstop. Batch lifecycle normally advances via
 * after-commit JobStateChanged listeners; a process dying between a member's
 * commit and its listener loses that event forever. Each test simulates the loss
 * by dropping the listener before the member transitions, then proves
 * BatchCoordinator::reconcile() re-derives the lost decision from the counters.
 */
final class BatchReconcileTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function jobwarden(): JobWarden
    {
        return $this->app->make(JobWarden::class);
    }

    private function coordinator(): BatchCoordinator
    {
        return $this->app->make(BatchCoordinator::class);
    }

    /** Simulate the crash window: member transitions commit, but no listener runs. */
    private function loseBatchEvents(): void
    {
        $this->app['events']->forget(JobStateChanged::class);
    }

    private function member(Batch $batch, string $jobClass): Job
    {
        return Job::where('batch_id', $batch->id)->where('job_class', $jobClass)->firstOrFail();
    }

    private function complete(Job $job, JobState $outcome): void
    {
        $sm = $this->app->make(StateMachine::class);
        $sm->applyJobTransition($job, JobState::Running, TransitionContext::for(ActorType::Worker));
        $sm->applyJobTransition($job->refresh(), $outcome, TransitionContext::for(ActorType::Worker));
    }

    public function test_reconcile_completes_a_batch_whose_last_member_event_was_lost(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $this->complete($this->member($batch, 'JobB'), JobState::Succeeded);

        // The event was lost: every member is terminal but the batch never heard.
        $batch->refresh();
        $this->assertSame(BatchState::Running, $batch->state);
        $this->assertSame(2, $batch->succeeded_count);

        $this->coordinator()->reconcile();

        $batch->refresh();
        $this->assertSame(BatchState::Succeeded, $batch->state);
        $this->assertSame('succeeded', $batch->summary['outcome']);
    }

    public function test_reconcile_applies_a_lost_fail_fast_verdict_and_cancels_the_rest(): void
    {
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')->add('b', 'JobB')->add('c', 'JobC')->dispatch();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $this->complete($this->member($batch, 'JobB'), JobState::Failed);
        // JobC is still queued — under fail_fast it should have been canceled.

        $this->coordinator()->reconcile();

        $batch->refresh();
        $this->assertSame(BatchState::Failed, $batch->state);

        $c = $this->member($batch, 'JobC');
        $this->assertSame(JobState::Canceled, $c->state);
        $this->assertTrue((bool) $c->cancel_requested);
    }

    public function test_reconcile_cancels_a_member_stranded_behind_a_failed_dependency(): void
    {
        $batch = $this->jobwarden()->batch('chain')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])->dispatch();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobA'), JobState::Failed);

        // The lost cascade left b stranded pending behind a dep that can never succeed.
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobB')->state);

        $this->coordinator()->reconcile();

        // One pass converges the chain: b canceled, then the batch completes partial.
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);
        $batch->refresh();
        $this->assertSame(BatchState::Partial, $batch->state);
        $this->assertSame('partial', $batch->summary['outcome']);
    }

    public function test_reconcile_reopens_the_batch_and_revives_dependents_after_a_lost_retry_event(): void
    {
        $batch = $this->jobwarden()->batch('chain')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])->dispatch();

        // Live path: a fails, b is canceled as unreachable, batch partial.
        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);

        // The operator retry commits, but its event is lost: the batch stays
        // partial with a queued member, and b stays canceled.
        $this->loseBatchEvents();
        $this->app->make(\JobWarden\Operations\OperatorActions::class)
            ->retry($this->member($batch, 'JobA'), 'operator retry', 'op-1');
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);

        $this->coordinator()->reconcile();

        $batch->refresh();
        $this->assertSame(BatchState::Running, $batch->state, 'reopened');
        $this->assertNull($batch->finished_at);

        $b = $this->member($batch, 'JobB');
        $this->assertSame(JobState::Pending, $b->state, 'revived to waiting on its predecessor');
        $this->assertFalse((bool) $b->cancel_requested);
    }

    public function test_reconcile_leaves_a_healthy_running_batch_alone(): void
    {
        $batch = $this->jobwarden()->batch('healthy')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        // Listeners intact — the live path handles this one; b is still in flight.
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);

        $this->coordinator()->reconcile();

        $batch->refresh();
        $this->assertSame(BatchState::Running, $batch->state);
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobB')->state);
    }

    public function test_the_global_reaper_tick_runs_the_batch_backstop(): void
    {
        $batch = $this->jobwarden()->batch('via-reaper')
            ->add('a', 'JobA')->dispatch();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);

        $wasLeader = $this->app->make(GlobalReaper::class)->tick('reaper-batch-test');
        $this->assertTrue($wasLeader);

        $batch->refresh();
        $this->assertSame(BatchState::Succeeded, $batch->state);
    }
}
