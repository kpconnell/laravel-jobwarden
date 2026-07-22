<?php

declare(strict_types=1);

namespace JobWarden\Tests\Batch;

use JobWarden\Batch\BatchCoordinator;
use JobWarden\Events\JobStateChanged;
use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Operations\OperatorActions;
use JobWarden\Recovery\Admitter;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use RuntimeException;

/**
 * `dependsOnCompletion` edges — finally semantics inside a batch. Where a
 * dependsOn edge is satisfied only by SUCCESS (and a non-succeeding upstream
 * cancels its dependents as unreachable), a dependsOnCompletion edge is
 * satisfied by the upstream merely ENDING, and never dooms anything.
 */
final class BatchCompletionEdgeTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    // -- admission ---------------------------------------------------------

    public function test_a_completion_dependent_is_admitted_after_its_upstream_fails(): void
    {
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state);
        $this->admit();
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state, 'gated while the upstream is in flight');

        $this->complete($this->member($batch, 'JobWork'), JobState::Failed);

        $cleanup = $this->member($batch, 'JobCleanup');
        $this->assertSame(JobState::Pending, $cleanup->state, 'not canceled as unreachable — it is reachable BECAUSE work ended');
        $this->assertFalse((bool) $cleanup->cancel_requested);
        $this->assertSame(BatchState::Running, $batch->refresh()->state, 'the batch waits for the finalizer');

        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobCleanup')->state, 'admitted on the upstream ending');

        $this->complete($this->member($batch, 'JobCleanup'), JobState::Succeeded);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state, 'work still failed: partial, not succeeded');
    }

    public function test_a_completion_dependent_is_admitted_after_its_upstream_succeeds(): void
    {
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobWork'), JobState::Succeeded);
        $this->admit();

        $this->assertSame(JobState::Queued, $this->member($batch, 'JobCleanup')->state);

        $this->complete($this->member($batch, 'JobCleanup'), JobState::Succeeded);
        $this->assertSame(BatchState::Succeeded, $batch->refresh()->state);
    }

    public function test_an_orphaned_upstream_still_gates_a_completion_dependent(): void
    {
        // `orphaned` is NOT terminal: the run's outcome is unknown and awaits an
        // operator verdict, so a finalizer must not fire on it.
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $sm = $this->app->make(StateMachine::class);
        $sm->applyJobTransition($this->member($batch, 'JobWork'), JobState::Running, TransitionContext::for(ActorType::Worker));
        $sm->applyJobTransition($this->member($batch, 'JobWork'), JobState::Orphaned, TransitionContext::for(ActorType::Reaper));

        $this->admit();
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state, 'an unresolved orphan still gates');
    }

    public function test_a_mixed_dependent_waits_on_its_on_success_edge_only(): void
    {
        // join needs u1 to SUCCEED and u2 merely to END.
        $batch = $this->jobwarden()->batch('mixed', 'continue')
            ->add('u1', 'JobU1')
            ->add('u2', 'JobU2')
            ->add('join', 'JobJoin', dependsOn: ['u1'], dependsOnCompletion: ['u2'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobU2'), JobState::Failed);
        $this->admit();

        $join = $this->member($batch, 'JobJoin');
        $this->assertSame(JobState::Pending, $join->state, 'a doomed on_completion upstream neither admits nor cancels it');
        $this->assertFalse((bool) $join->cancel_requested);

        $this->complete($this->member($batch, 'JobU1'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobJoin')->state, 'admitted once BOTH edges are satisfied');
    }

    public function test_a_mixed_dependent_is_unreachable_when_an_on_success_upstream_is_doomed(): void
    {
        $batch = $this->jobwarden()->batch('mixed', 'continue')
            ->add('u1', 'JobU1')
            ->add('u2', 'JobU2')
            ->add('join', 'JobJoin', dependsOn: ['u1'], dependsOnCompletion: ['u2'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobU1'), JobState::Failed);

        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobJoin')->state, 'the on_success edge can never be satisfied');
        $this->assertSame(self::UNREACHABLE, $this->member($batch, 'JobJoin')->cancel_reason);
    }

    // -- eager failure policies --------------------------------------------

    public function test_fail_fast_spares_the_finalizer_and_its_tail(): void
    {
        // The case the feature exists for: finally must run precisely when the
        // batch is failing. The batch still fails IMMEDIATELY; the spared
        // members run on afterwards.
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')
            ->add('b', 'JobB')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->add('notify', 'JobNotify', dependsOn: ['cleanup'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);

        $this->assertSame(BatchState::Failed, $batch->refresh()->state, 'the verdict is still eager');
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state, 'ordinary members are still swept');

        foreach (['JobCleanup', 'JobNotify'] as $class) {
            $spared = $this->member($batch, $class);
            $this->assertSame(JobState::Pending, $spared->state, "{$class} spared by the eager sweep");
            $this->assertFalse((bool) $spared->cancel_requested);
        }

        // The finalizer closure runs to completion on a batch that already failed.
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobCleanup')->state);
        $this->complete($this->member($batch, 'JobCleanup'), JobState::Succeeded);

        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobNotify')->state, 'the finalizer tail follows it');
        $this->complete($this->member($batch, 'JobNotify'), JobState::Succeeded);

        $this->assertSame(BatchState::Failed, $batch->refresh()->state, 'a clean finalizer does not undo the verdict');
    }

    public function test_a_spared_finalizer_that_fails_still_cancels_its_own_dependents(): void
    {
        // Inside the spared subtree the ordinary DAG rules resume.
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->add('notify', 'JobNotify', dependsOn: ['cleanup'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->admit();
        $this->complete($this->member($batch, 'JobCleanup'), JobState::Failed);

        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobNotify')->state);
        $this->assertSame(self::UNREACHABLE, $this->member($batch, 'JobNotify')->cancel_reason);
    }

    public function test_threshold_spares_the_finalizer_too(): void
    {
        $batch = $this->jobwarden()->batch('thr', 'threshold', failureThreshold: 1)
            ->add('a', 'JobA')
            ->add('b', 'JobB')
            ->add('c', 'JobC')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Running, $batch->refresh()->state, 'one failure is tolerated');

        $this->complete($this->member($batch, 'JobB'), JobState::Failed); // 2 > threshold 1

        $this->assertSame(BatchState::Failed, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobC')->state);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state);
    }

    public function test_canceling_the_batch_cancels_finalizers_too(): void
    {
        // An operator canceling the batch means "stop everything" — that verdict
        // is not the failure path a finalizer exists to handle.
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->jobwarden()->cancelBatch($batch, 'operator changed their mind', 'op-1');

        $this->assertSame(BatchState::Canceled, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobCleanup')->state);
    }

    public function test_a_finalizer_failure_lands_in_the_batch_verdict(): void
    {
        // A failed cleanup is a real failure: it counts like any other member.
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobWork'), JobState::Succeeded);
        $this->admit();
        $this->complete($this->member($batch, 'JobCleanup'), JobState::Failed);

        $batch->refresh();
        $this->assertSame(BatchState::Partial, $batch->state);
        $this->assertSame(1, $batch->failed_count);
    }

    // -- revival -----------------------------------------------------------

    public function test_a_doomed_completion_upstream_never_blocks_a_revival(): void
    {
        // join was canceled because u1 (on_success) failed. Retrying u1 must
        // revive it even though u2 is still failed — u2's edge was never what
        // doomed it.
        $batch = $this->jobwarden()->batch('mixed', 'continue')
            ->add('u1', 'JobU1')
            ->add('u2', 'JobU2')
            ->add('join', 'JobJoin', dependsOn: ['u1'], dependsOnCompletion: ['u2'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobU1'), JobState::Failed);
        $this->complete($this->member($batch, 'JobU2'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobJoin')->state);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);

        $this->app->make(OperatorActions::class)->retry($this->member($batch, 'JobU1'), 'retry u1', 'op-1');

        $this->assertSame(BatchState::Running, $batch->refresh()->state);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobJoin')->state, 'revived: only u1 doomed it');
    }

    // -- the event-loss backstop -------------------------------------------

    public function test_reconcile_does_not_strand_a_completion_dependent(): void
    {
        $batch = $this->jobwarden()->batch('etl', 'continue')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobWork'), JobState::Failed);

        $this->coordinator()->reconcile();

        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state, 'not swept as stranded');
        $this->assertSame(BatchState::Running, $batch->refresh()->state, 'nor completed around it');

        $this->admit();
        $this->complete($this->member($batch, 'JobCleanup'), JobState::Succeeded);
        $this->coordinator()->reconcile();

        $this->assertSame(BatchState::Partial, $batch->refresh()->state);
    }

    public function test_reconcile_sweeps_a_finalizer_tail_stranded_on_a_failed_batch(): void
    {
        // The spared subtree keeps running on an eagerly-failed batch, so a lost
        // event inside it strands a dependent exactly as it would in a live
        // batch — and the batch will never complete again to clean it up.
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->add('notify', 'JobNotify', dependsOn: ['cleanup'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Failed, $batch->refresh()->state);
        $this->admit();

        $this->loseBatchEvents();
        $this->complete($this->member($batch, 'JobCleanup'), JobState::Failed);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobNotify')->state, 'the cascade event was lost');

        $this->coordinator()->reconcile();

        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobNotify')->state);
    }

    public function test_reconcile_revives_past_a_doomed_completion_upstream(): void
    {
        $batch = $this->jobwarden()->batch('mixed', 'continue')
            ->add('u1', 'JobU1')
            ->add('u2', 'JobU2')
            ->add('join', 'JobJoin', dependsOn: ['u1'], dependsOnCompletion: ['u2'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobU1'), JobState::Failed);
        $this->complete($this->member($batch, 'JobU2'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobJoin')->state);

        // The retry commits but its event is lost — the backstop must re-derive it.
        $this->loseBatchEvents();
        $this->app->make(OperatorActions::class)->retry($this->member($batch, 'JobU1'), 'retry u1', 'op-1');
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);

        $this->coordinator()->reconcile();

        $this->assertSame(BatchState::Running, $batch->refresh()->state, 'reopened');
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobJoin')->state, 'revived despite the failed on_completion upstream');
    }

    // -- the builder surface -----------------------------------------------

    public function test_a_member_with_only_completion_deps_starts_pending(): void
    {
        $batch = $this->jobwarden()->batch('etl')
            ->add('work', 'JobWork')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->assertSame(JobState::Queued, $this->member($batch, 'JobWork')->state, 'the root is claimable now');
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobCleanup')->state, 'the finalizer waits');
    }

    public function test_the_same_key_under_both_conditions_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('an edge carries one condition');

        $this->jobwarden()->batch('bad')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'], dependsOnCompletion: ['a'])
            ->dispatch();
    }

    public function test_a_cycle_through_a_completion_edge_is_rejected(): void
    {
        // An on_completion cycle deadlocks exactly as an on_success one does.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $this->jobwarden()->batch('cyclic')
            ->add('a', 'JobA', dependsOnCompletion: ['b'])
            ->add('b', 'JobB', dependsOn: ['a'])
            ->dispatch();
    }

    // -- helpers -----------------------------------------------------------

    /** The cancel_reason the unreachable-dependents cascade stamps. */
    private const UNREACHABLE = 'unreachable: an upstream dependency did not succeed';

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

    private function admit(): void
    {
        $this->app->make(Admitter::class)->admit();
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
}
