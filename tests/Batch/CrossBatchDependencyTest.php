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
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Cross-batch dependencies (spec §8.5): a batch (or standalone job) gated on
 * ALREADY-DISPATCHED upstream batches. dependsOnBatches requires the upstream
 * to reach `succeeded` — strictly, `partial` dooms — and a doomed dependent is
 * canceled as unreachable and revived if the upstream reopens.
 * dependsOnBatchCompletion admits once the upstream is terminal AND quiescent.
 */
final class CrossBatchDependencyTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    // -- admission ---------------------------------------------------------

    public function test_a_success_edge_gates_until_the_upstream_batch_succeeds(): void
    {
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('report')
            ->dependsOnBatches([$b1])
            ->add('summarize', 'JobSummarize')
            ->add('email', 'JobEmail', dependsOn: ['summarize'])
            ->dispatch();

        $this->assertSame(JobState::Pending, $this->member($b2, 'JobSummarize')->state, 'roots wait on the batch dep');
        $this->admit();
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobSummarize')->state, 'gated while the upstream runs');

        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->assertSame(BatchState::Succeeded, $b1->refresh()->state);

        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobSummarize')->state, 'admitted on upstream success');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobEmail')->state, 'non-roots still follow the intra-batch DAG');
    }

    public function test_a_partial_upstream_satisfies_a_completion_edge(): void
    {
        $b1 = $this->warden()->batch('mixed', 'continue')
            ->add('ok', 'JobOk')
            ->add('bad', 'JobBad')
            ->dispatch();
        $b2 = $this->warden()->batch('after')
            ->dependsOnBatchCompletion([$b1])
            ->add('report', 'JobReport')
            ->dispatch();

        $this->complete($this->member($b1, 'JobOk'), JobState::Succeeded);
        $this->complete($this->member($b1, 'JobBad'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $b1->refresh()->state);

        $report = $this->member($b2, 'JobReport');
        $this->assertSame(JobState::Pending, $report->state, 'never doomed by the verdict');
        $this->assertFalse((bool) $report->cancel_requested);

        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobReport')->state);
    }

    public function test_a_completion_edge_waits_for_a_failed_upstream_to_go_quiescent(): void
    {
        // Terminal is not quiescent: an eagerly-failed batch keeps its spared
        // finalizer subtree in flight, and "run after that batch" must wait for
        // that work to drain too.
        $b1 = $this->warden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->dispatch();
        $b2 = $this->warden()->batch('after')
            ->dependsOnBatchCompletion([$b1])
            ->add('report', 'JobReport')
            ->dispatch();

        $this->complete($this->member($b1, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Failed, $b1->refresh()->state);

        $this->admit(); // promotes b1's spared finalizer, NOT b2's root
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobReport')->state, 'terminal but not quiescent');
        $this->assertSame(JobState::Queued, $this->member($b1, 'JobCleanup')->state);

        $this->complete($this->member($b1, 'JobCleanup'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobReport')->state, 'admitted once the finalizer drained');
    }

    public function test_a_standalone_job_is_gated_and_admitted_the_same_way(): void
    {
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();
        $job = $this->warden()->dispatch('JobStandalone', [], ['depends_on_batches' => [$b1]]);

        $this->assertSame(JobState::Pending, $job->state);
        $this->admit();
        $this->assertSame(JobState::Pending, $job->refresh()->state);

        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_the_guard_itself_blocks_a_direct_promotion(): void
    {
        // The Admitter's window pre-filters gated rows, so most tests never let
        // the guard decide. Drive the transition directly: if the guard's
        // batch-dep arm ever diverges from the window predicate (the invariant
        // both files warn about), this is the test that notices.
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        $sm = $this->app->make(StateMachine::class);
        try {
            $sm->applyJobTransition($this->member($b2, 'JobWork'), JobState::Queued, TransitionContext::for(ActorType::System));
            $this->fail('expected the DepsSatisfiedGuard to refuse');
        } catch (GuardFailedException) {
            // gated: the upstream batch is still running
        }
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state);

        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $sm->applyJobTransition($this->member($b2, 'JobWork'), JobState::Queued, TransitionContext::for(ActorType::System));
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobWork')->state);
    }

    public function test_a_standalone_completion_dependent_fires_whatever_the_verdict(): void
    {
        $b1 = $this->warden()->batch('upstream', 'fail_fast')->add('u', 'JobU')->dispatch();
        $job = $this->warden()->dispatch('JobStandalone', [], ['depends_on_batch_completion' => [$b1->id]]);

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);

        $job->refresh();
        $this->assertSame(JobState::Pending, $job->state, 'never doomed by the verdict');
        $this->assertFalse((bool) $job->cancel_requested);

        $this->admit();
        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_an_orphaned_finalizer_holds_the_completion_gate(): void
    {
        // Quiescence counts a parked orphan as unfinished work — its outcome is
        // unknown and awaits an operator verdict, so "after that batch" has not
        // arrived yet. The same rule an orphaned upstream JOB applies intra-batch.
        $b1 = $this->warden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['a'])
            ->dispatch();
        $b2 = $this->warden()->batch('after')
            ->dependsOnBatchCompletion([$b1])
            ->add('report', 'JobReport')
            ->dispatch();

        $this->complete($this->member($b1, 'JobA'), JobState::Failed);
        $this->admit(); // the spared finalizer is promoted

        $sm = $this->app->make(StateMachine::class);
        $sm->applyJobTransition($this->member($b1, 'JobCleanup'), JobState::Running, TransitionContext::for(ActorType::Worker));
        $sm->applyJobTransition($this->member($b1, 'JobCleanup'), JobState::Orphaned, TransitionContext::for(ActorType::Reaper));

        $this->admit();
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobReport')->state, 'an unresolved orphan still holds the gate');
    }

    public function test_batch_deps_fan_down_to_root_members_only(): void
    {
        $b1 = $this->warden()->batch('u1')->add('x', 'JobX')->dispatch();
        $b2 = $this->warden()->batch('u2')->add('y', 'JobY')->dispatch();
        $b3 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1])
            ->dependsOnBatchCompletion([$b2])
            ->add('r1', 'JobR1')
            ->add('r2', 'JobR2')
            ->add('c', 'JobC', dependsOn: ['r1'])
            ->dispatch();

        $edges = $this->jobwarden()->table($this->tbl('job_batch_dependencies'))->get();
        $this->assertCount(4, $edges, '2 roots x 2 upstream batches');
        $this->assertSame(2, $edges->where('depends_on_batch_id', (string) $b1->id)->where('edge_condition', 'on_success')->count());
        $this->assertSame(2, $edges->where('depends_on_batch_id', (string) $b2->id)->where('edge_condition', 'on_completion')->count());
        $this->assertSame(0, $edges->where('job_id', (string) $this->member($b3, 'JobC')->id)->count(), 'non-roots carry no batch edges');
    }

    // -- the doom cascade --------------------------------------------------

    public function test_a_failed_upstream_dooms_the_dependents_but_spares_the_finally_subtree(): void
    {
        $b1 = $this->warden()->batch('upstream', 'fail_fast')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1])
            ->add('work', 'JobWork')
            ->add('notify', 'JobNotify', dependsOn: ['work'])
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(BatchState::Failed, $b1->refresh()->state);

        $work = $this->member($b2, 'JobWork');
        $this->assertSame(JobState::Canceled, $work->state);
        $this->assertSame(self::BATCH_UNREACHABLE, $work->cancel_reason, 'the cross-batch sentinel, not the job one');

        $notify = $this->member($b2, 'JobNotify');
        $this->assertSame(JobState::Canceled, $notify->state, 'the intra-batch cascade dooms the tail');
        $this->assertSame(self::UNREACHABLE, $notify->cancel_reason);

        $cleanup = $this->member($b2, 'JobCleanup');
        $this->assertSame(JobState::Pending, $cleanup->state, 'the finally member is spared');
        $this->assertFalse((bool) $cleanup->cancel_requested);
        $this->assertSame(BatchState::Running, $b2->refresh()->state, 'the batch waits for its finalizer');

        $this->admit();
        $this->complete($this->member($b2, 'JobCleanup'), JobState::Succeeded);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
    }

    public function test_a_partial_upstream_dooms_strictly(): void
    {
        $b1 = $this->warden()->batch('mixed', 'continue')
            ->add('ok', 'JobOk')
            ->add('bad', 'JobBad')
            ->dispatch();
        $b2 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1])
            ->add('work', 'JobWork')
            ->dispatch();

        $this->complete($this->member($b1, 'JobOk'), JobState::Succeeded);
        $this->complete($this->member($b1, 'JobBad'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $b1->refresh()->state);

        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state, 'partial is not success');
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
    }

    public function test_the_first_doomed_upstream_dooms_regardless_of_the_other(): void
    {
        $b1a = $this->warden()->batch('up-a', 'fail_fast')->add('ua', 'JobUa')->dispatch();
        $b1b = $this->warden()->batch('up-b')->add('ub', 'JobUb')->dispatch();
        $b2 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1a, $b1b])
            ->add('work', 'JobWork')
            ->dispatch();

        $this->complete($this->member($b1a, 'JobUa'), JobState::Failed);

        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state);
        $this->assertSame(BatchState::Running, $b1b->refresh()->state, 'the other upstream is untouched');
    }

    public function test_doom_propagates_through_a_chain_of_batches(): void
    {
        $b1 = $this->warden()->batch('one', 'fail_fast')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('two')->dependsOnBatches([$b1])->add('w2', 'JobW2')->dispatch();
        $b3 = $this->warden()->batch('three')->dependsOnBatches([$b2])->add('w3', 'JobW3')->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);

        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobW2')->state);
        $this->assertSame(BatchState::Partial, $b3->refresh()->state, 'doom crosses every hop');
        $this->assertSame(self::BATCH_UNREACHABLE, $this->member($b3, 'JobW3')->cancel_reason);
    }

    public function test_canceling_an_upstream_batch_dooms_its_dependents(): void
    {
        // An operator-canceled upstream can never reopen (reopenBatch takes only
        // partial/failed), so this doom is permanent — the strict rule's answer
        // to "the upstream will never succeed now".
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        $this->warden()->cancelBatch($b1, 'operator stop', 'op-1');

        $this->assertSame(BatchState::Canceled, $b1->refresh()->state);
        $work = $this->member($b2, 'JobWork');
        $this->assertSame(JobState::Canceled, $work->state);
        $this->assertSame(self::BATCH_UNREACHABLE, $work->cancel_reason);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
    }

    public function test_a_standalone_dependent_is_canceled_when_the_upstream_dooms(): void
    {
        $b1 = $this->warden()->batch('upstream', 'fail_fast')->add('u', 'JobU')->dispatch();
        $job = $this->warden()->dispatch('JobStandalone', [], ['depends_on_batches' => [(string) $b1->id]]);

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);

        $job->refresh();
        $this->assertSame(JobState::Canceled, $job->state);
        $this->assertSame(self::BATCH_UNREACHABLE, $job->cancel_reason);
    }

    // -- revival -----------------------------------------------------------

    public function test_reopening_the_upstream_revives_the_dependent_batch(): void
    {
        $b1 = $this->warden()->batch('upstream', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1])
            ->add('work', 'JobWork')
            ->add('next', 'JobNext', dependsOn: ['work'])
            ->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state);
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobNext')->state);

        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');

        $this->assertSame(BatchState::Running, $b1->refresh()->state);
        $this->assertSame(BatchState::Running, $b2->refresh()->state, 'the dependent batch reopened with it');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state, 'revived to waiting');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobNext')->state, 'the intra-batch revive cascade followed');

        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobWork')->state, 'admitted after the upstream re-succeeds');
    }

    public function test_revival_waits_until_every_doomed_upstream_reopens(): void
    {
        $b1a = $this->warden()->batch('up-a', 'continue')->add('ua', 'JobUa')->dispatch();
        $b1b = $this->warden()->batch('up-b', 'continue')->add('ub', 'JobUb')->dispatch();
        $b2 = $this->warden()->batch('down')
            ->dependsOnBatches([$b1a, $b1b])
            ->add('work', 'JobWork')
            ->dispatch();

        $this->complete($this->member($b1a, 'JobUa'), JobState::Failed);
        $this->complete($this->member($b1b, 'JobUb'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state);

        $this->app->make(OperatorActions::class)->retry($this->member($b1a, 'JobUa'), 'retry ua', 'op-1');
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state, 'the other upstream still dooms it');

        $this->app->make(OperatorActions::class)->retry($this->member($b1b, 'JobUb'), 'retry ub', 'op-1');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state, 'revived once no doomed upstream remains');
    }

    public function test_revival_propagates_through_a_chain_of_batches(): void
    {
        // The recursive path: reviveDependent reopens the dependent's own
        // partial batch, whose reopen hook revives ITS dependents — hop by hop.
        $b1 = $this->warden()->batch('one', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('two')->dependsOnBatches([$b1])->add('w2', 'JobW2')->dispatch();
        $b3 = $this->warden()->batch('three')->dependsOnBatches([$b2])->add('w3', 'JobW3')->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $b3->refresh()->state, 'doom reached the last hop');

        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');

        $this->assertSame(BatchState::Running, $b1->refresh()->state);
        $this->assertSame(BatchState::Running, $b2->refresh()->state);
        $this->assertSame(BatchState::Running, $b3->refresh()->state, 'revival reached the last hop');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobW2')->state);
        $this->assertSame(JobState::Pending, $this->member($b3, 'JobW3')->state);

        // And the chain re-runs end to end, each hop gated on the one before.
        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobW2')->state);
        $this->assertSame(JobState::Pending, $this->member($b3, 'JobW3')->state, 'still gated on the middle batch');

        $this->complete($this->member($b2, 'JobW2'), JobState::Succeeded);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b3, 'JobW3')->state);
    }

    public function test_an_operator_cancel_verdict_is_never_revived(): void
    {
        $b1 = $this->warden()->batch('upstream', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state);

        // An operator re-cancels with their own verdict; the cascade must not undo it.
        $this->jobwarden()->table($this->tbl('jobs'))
            ->where('id', $this->member($b2, 'JobWork')->id)
            ->update(['cancel_reason' => 'operator: keep this dead']);

        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');

        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state, 'only the system undoes its own cascade');
    }

    // -- dispatch-time -----------------------------------------------------

    public function test_dispatching_against_an_unknown_batch_throws_and_creates_nothing(): void
    {
        try {
            $this->warden()->batch('down')
                ->dependsOnBatches([(string) Uuid::v7()])
                ->add('work', 'JobWork')
                ->dispatch();
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('depends on unknown batch', $e->getMessage());
        }

        $this->assertSame(0, $this->jobwarden()->table($this->tbl('batches'))->count());
        $this->assertSame(0, $this->jobwarden()->table($this->tbl('jobs'))->count());
        $this->assertSame(0, $this->jobwarden()->table($this->tbl('job_batch_dependencies'))->count());
    }

    public function test_a_standalone_dispatch_against_an_unknown_batch_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown batch');

        $this->warden()->dispatch('JobStandalone', [], ['depends_on_batches' => [(string) Uuid::v7()]]);
    }

    public function test_the_same_upstream_under_both_conditions_is_rejected(): void
    {
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('an edge carries one condition');

        $this->warden()->batch('down')
            ->dependsOnBatches([$b1])
            ->dependsOnBatchCompletion([$b1]);
    }

    public function test_dispatching_against_a_succeeded_upstream_is_admitted_on_the_next_tick(): void
    {
        $b1 = $this->warden()->batch('upstream')->add('u', 'JobU')->dispatch();
        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->assertSame(BatchState::Succeeded, $b1->refresh()->state);

        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state, 'never eligible at dispatch');
        $this->assertSame(1, $this->jobwarden()->table($this->tbl('job_batch_dependencies'))->count(), 'the satisfied edge is still recorded');

        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b2, 'JobWork')->state);
    }

    public function test_dispatching_against_an_already_doomed_upstream_is_doomed_immediately(): void
    {
        $b1 = $this->warden()->batch('upstream', 'fail_fast')->add('u', 'JobU')->dispatch();
        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(BatchState::Failed, $b1->refresh()->state);

        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        // The post-commit fast path — no admit tick, no reaper sweep needed.
        $work = $this->member($b2, 'JobWork');
        $this->assertSame(JobState::Canceled, $work->state);
        $this->assertSame(self::BATCH_UNREACHABLE, $work->cancel_reason);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
    }

    // -- the event-loss backstop -------------------------------------------

    public function test_reconcile_dooms_dependents_after_a_silent_upstream_flip(): void
    {
        $b1 = $this->warden()->batch('upstream', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();
        $b3 = $this->warden()->batch('after')->dependsOnBatchCompletion([$b1])->add('report', 'JobReport')->dispatch();

        // The member's failure commits its counters, but every listener is lost;
        // the completion CAS "committed on the crashed process" via direct update.
        $this->loseBatchEvents();
        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->jobwarden()->table($this->tbl('batches'))->where('id', $b1->id)
            ->update(['state' => BatchState::Partial->value]);

        $this->coordinator()->reconcile();

        $work = $this->member($b2, 'JobWork');
        $this->assertSame(JobState::Canceled, $work->state);
        $this->assertSame(self::BATCH_UNREACHABLE, $work->cancel_reason);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state, 'one sweep converges doom and completion');

        $report = $this->member($b3, 'JobReport');
        $this->assertSame(JobState::Pending, $report->state, 'a completion dependent is never stranded');
        $this->assertFalse((bool) $report->cancel_requested);
        $this->admit();
        $this->assertSame(JobState::Queued, $this->member($b3, 'JobReport')->state, 'partial-and-quiescent admits it');

        // Idempotent: a second sweep finds nothing to redo.
        $this->coordinator()->reconcile();
        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobWork')->state);
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);
    }

    public function test_reconcile_revives_dependents_after_a_silent_upstream_reopen(): void
    {
        $b1 = $this->warden()->batch('upstream', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')->dependsOnBatches([$b1])->add('work', 'JobWork')->dispatch();

        $this->complete($this->member($b1, 'JobU'), JobState::Failed); // live doom
        $this->assertSame(BatchState::Partial, $b2->refresh()->state);

        // The retry commits and the upstream's reopen CAS lands, but the revive
        // cascade dies with its process.
        $this->loseBatchEvents();
        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');
        $this->jobwarden()->table($this->tbl('batches'))->where('id', $b1->id)
            ->update(['state' => BatchState::Running->value, 'finished_at' => null, 'summary' => null]);

        $this->coordinator()->reconcile();

        $this->assertSame(BatchState::Running, $b2->refresh()->state, 'the dependent partial batch was reopened');
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state);

        $this->coordinator()->reconcile(); // idempotent
        $this->assertSame(JobState::Pending, $this->member($b2, 'JobWork')->state);
    }

    public function test_reconcile_revives_a_standalone_dependent(): void
    {
        $b1 = $this->warden()->batch('upstream', 'continue')->add('u', 'JobU')->dispatch();
        $job = $this->warden()->dispatch('JobStandalone', [], ['depends_on_batches' => [$b1->id]]);

        $this->complete($this->member($b1, 'JobU'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $job->refresh()->state);

        $this->loseBatchEvents();
        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');
        $this->jobwarden()->table($this->tbl('batches'))->where('id', $b1->id)
            ->update(['state' => BatchState::Running->value, 'finished_at' => null, 'summary' => null]);

        $this->coordinator()->reconcile();
        $this->assertSame(JobState::Pending, $job->refresh()->state, 'no own-batch verdict gates a standalone revival');

        // And the full loop closes: re-success, completion re-derived, admitted.
        $this->complete($this->member($b1, 'JobU'), JobState::Succeeded);
        $this->coordinator()->reconcile();
        $this->assertSame(BatchState::Succeeded, $b1->refresh()->state);
        $this->admit();
        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_reconcile_does_not_revive_while_a_cross_batch_upstream_is_still_doomed(): void
    {
        // The flap guard: revivableMemberIds must not revive a member the
        // stranded batch-dep pass would immediately re-cancel. The builder only
        // puts batch edges on roots, but the schema permits any job→batch edge
        // and the sweeps must be robust to one.
        $b1 = $this->warden()->batch('gate', 'continue')->add('u', 'JobU')->dispatch();
        $b2 = $this->warden()->batch('down')
            ->add('a', 'JobA')
            ->add('x', 'JobX', dependsOn: ['a'])
            ->dispatch();
        $this->jobwarden()->table($this->tbl('job_batch_dependencies'))->insert([
            'job_id' => (string) $this->member($b2, 'JobX')->id,
            'depends_on_batch_id' => (string) $b1->id,
            'edge_condition' => 'on_success',
        ]);

        $this->complete($this->member($b2, 'JobA'), JobState::Failed); // x canceled, intra-batch sentinel
        $this->complete($this->member($b1, 'JobU'), JobState::Failed); // the batch dep dooms too
        $this->assertSame(self::UNREACHABLE, $this->member($b2, 'JobX')->cancel_reason);

        // Heal the job dep with its revival event lost.
        $this->loseBatchEvents();
        $this->app->make(OperatorActions::class)->retry($this->member($b2, 'JobA'), 'retry a', 'op-1');
        $this->coordinator()->reconcile();

        $this->assertSame(JobState::Canceled, $this->member($b2, 'JobX')->state, 'the doomed batch dep still holds it');

        // Heal the batch dep: its reopen revives x despite the intra-batch sentinel.
        $this->app->make(OperatorActions::class)->retry($this->member($b1, 'JobU'), 'retry u', 'op-1');
        $this->coordinator()->reconcile();

        $this->assertSame(JobState::Pending, $this->member($b2, 'JobX')->state);
    }

    // -- the admit window --------------------------------------------------

    public function test_the_admit_window_is_not_starved_by_a_batch_blocked_backlog(): void
    {
        // Mirror of the job-dep starvation regression: pending rows blocked on a
        // batch dep must not consume the LIMIT and park an eligible successor.
        $t0 = Carbon::parse('2026-07-25 12:00:00');

        try {
            Carbon::setTestNow($t0);
            $gate = $this->warden()->batch('gate')->add('u', 'JobU')->dispatch();
            $blocked = $this->warden()->batch('blocked')->dependsOnBatches([$gate]);
            foreach (range(0, 4) as $i) {
                $blocked->add("r{$i}", "JobR{$i}");
            }
            $blocked->dispatch();

            Carbon::setTestNow($t0->copy()->addSeconds(10));
            $late = $this->warden()->batch('late')
                ->add('b0', 'JobB0')
                ->add('b1', 'JobB1', dependsOn: ['b0'])
                ->dispatch();
            $this->complete($this->member($late, 'JobB0'), JobState::Succeeded);

            // limit 5 = exactly the blocked batch's five roots; b1 sorts last.
            $this->admit(limit: 5);

            $this->assertSame(JobState::Queued, $this->member($late, 'JobB1')->state, 'not starved by the blocked backlog');
        } finally {
            Carbon::setTestNow();
        }
    }

    // -- helpers -----------------------------------------------------------

    /** The intra-batch cascade's cancel_reason. */
    private const UNREACHABLE = 'unreachable: an upstream dependency did not succeed';

    /** The cross-batch cascade's cancel_reason. */
    private const BATCH_UNREACHABLE = 'unreachable: an upstream batch did not succeed';

    private function warden(): JobWarden
    {
        return $this->app->make(JobWarden::class);
    }

    private function coordinator(): BatchCoordinator
    {
        return $this->app->make(BatchCoordinator::class);
    }

    /** Simulate the crash window: transitions commit, but no listener runs. */
    private function loseBatchEvents(): void
    {
        $this->app['events']->forget(JobStateChanged::class);
    }

    private function admit(int $limit = 200): void
    {
        $this->app->make(Admitter::class)->admit($limit);
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
