<?php

declare(strict_types=1);

namespace JobWarden\Tests\Batch;

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
use Illuminate\Support\Carbon;
use RuntimeException;

final class BatchTest extends TestCase
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

    public function test_fanout_all_succeed_makes_the_batch_succeeded(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a', 'JobA')->add('b', 'JobB')->add('c', 'JobC')->dispatch();

        $this->assertSame(BatchState::Running, $batch->state);
        $this->assertSame(3, $batch->total_jobs);
        $this->assertSame(3, $batch->pending_count);

        foreach (['JobA', 'JobB', 'JobC'] as $class) {
            $this->complete($this->member($batch, $class), JobState::Succeeded);
        }

        $batch->refresh();
        $this->assertSame(BatchState::Succeeded, $batch->state);
        $this->assertSame(3, $batch->succeeded_count);
        $this->assertSame('succeeded', $batch->summary['outcome']);
    }

    public function test_continue_with_a_failure_makes_the_batch_partial(): void
    {
        $batch = $this->jobwarden()->batch('fanout', 'continue')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $this->complete($this->member($batch, 'JobB'), JobState::Failed);

        $batch->refresh();
        $this->assertSame(BatchState::Partial, $batch->state);
        $this->assertSame(1, $batch->succeeded_count);
        $this->assertSame(1, $batch->failed_count);
    }

    public function test_fail_fast_cancels_remaining_members_and_fails_the_batch(): void
    {
        $batch = $this->jobwarden()->batch('fanout', 'fail_fast')
            ->add('a', 'JobA')->add('b', 'JobB')->add('c', 'JobC')->dispatch();

        // First member fails → fail_fast.
        $this->complete($this->member($batch, 'JobA'), JobState::Failed);

        $batch->refresh();
        $this->assertSame(BatchState::Failed, $batch->state);
        // The remaining (still-queued) members were canceled.
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobC')->state);
    }

    public function test_threshold_tolerates_failures_then_fails_when_exceeded(): void
    {
        $batch = $this->jobwarden()->batch('fanout', 'threshold', failureThreshold: 1)
            ->add('a', 'JobA')->add('b', 'JobB')->add('c', 'JobC')->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed); // 1 failure: tolerated
        $this->assertSame(BatchState::Running, $batch->refresh()->state);

        $this->complete($this->member($batch, 'JobB'), JobState::Failed); // 2 > threshold 1
        $batch->refresh();
        $this->assertSame(BatchState::Failed, $batch->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobC')->state);
    }

    public function test_chain_admits_each_dependent_as_its_predecessor_succeeds(): void
    {
        $batch = $this->jobwarden()->batch('chain')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])
            ->add('c', 'JobC', dependsOn: ['b'])
            ->dispatch();

        // Only the root is eligible at first.
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobA')->state);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobB')->state);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobC')->state);

        $admitter = $this->app->make(Admitter::class);

        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $admitter->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobB')->state, 'b admitted after a');
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobC')->state, 'c still gated by b');

        $this->complete($this->member($batch, 'JobB'), JobState::Succeeded);
        $admitter->admit();
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobC')->state, 'c admitted after b');

        $this->complete($this->member($batch, 'JobC'), JobState::Succeeded);
        $this->assertSame(BatchState::Succeeded, $batch->refresh()->state);
    }

    public function test_continue_with_a_failed_upstream_does_not_strand_dependents(): void
    {
        // continue policy, but b depends on a. Strict deps mean a failed `a`
        // makes `b` UNREACHABLE — it must not be left stuck pending forever
        // (which would hang the batch). It should be canceled and the batch
        // should complete as partial.
        $batch = $this->jobwarden()->batch('chain', 'continue')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])
            ->add('c', 'JobC', dependsOn: ['b'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);

        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state, 'b is unreachable → canceled');
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobC')->state, 'c (transitively) unreachable → canceled');
        $this->assertSame(BatchState::Partial, $batch->refresh()->state, 'batch completes (partial), not hung');
    }

    public function test_retrying_a_failed_upstream_revives_its_canceled_dependents(): void
    {
        // Fail the chain's root: b and c are canceled as unreachable and the
        // batch completes partial. An operator retry of the root must undo that
        // cascade — dependents back to pending (waiting on the root), batch
        // reopened — and the chain must then be able to run to success.
        $batch = $this->jobwarden()->batch('chain', 'continue')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])
            ->add('c', 'JobC', dependsOn: ['b'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);

        $this->app->make(OperatorActions::class)->retry($this->member($batch, 'JobA'), 'operator retry', 'op-1');

        $batch->refresh();
        $this->assertSame(BatchState::Running, $batch->state, 'batch reopened');
        $this->assertNull($batch->finished_at);
        $this->assertSame(JobState::Queued, $this->member($batch, 'JobA')->state);

        $b = $this->member($batch, 'JobB');
        $this->assertSame(JobState::Pending, $b->state, 'b waits on its predecessor again');
        $this->assertFalse((bool) $b->cancel_requested);
        $this->assertNull($b->cancel_reason);
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobC')->state, 'revival cascades transitively');

        // The revived chain runs to completion exactly like a fresh one.
        $admitter = $this->app->make(Admitter::class);
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $admitter->admit();
        $this->complete($this->member($batch, 'JobB'), JobState::Succeeded);
        $admitter->admit();
        $this->complete($this->member($batch, 'JobC'), JobState::Succeeded);

        $batch->refresh();
        $this->assertSame(BatchState::Succeeded, $batch->state);
        $this->assertSame(3, $batch->succeeded_count);
        $this->assertSame(0, $batch->canceled_count);
    }

    public function test_a_dependent_behind_a_second_failed_upstream_revives_only_after_both_retries(): void
    {
        // join depends on BOTH roots. Retrying only u1 must not revive join —
        // it is still unreachable behind the failed u2 (and would otherwise
        // just be re-canceled by the stranded sweep).
        $batch = $this->jobwarden()->batch('diamond', 'continue')
            ->add('u1', 'JobU1')
            ->add('u2', 'JobU2')
            ->add('join', 'JobJoin', dependsOn: ['u1', 'u2'])
            ->dispatch();

        $this->complete($this->member($batch, 'JobU1'), JobState::Failed);
        $this->complete($this->member($batch, 'JobU2'), JobState::Failed);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobJoin')->state);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);

        $operator = $this->app->make(OperatorActions::class);

        $operator->retry($this->member($batch, 'JobU1'), 'retry u1', 'op-1');
        $this->assertSame(BatchState::Running, $batch->refresh()->state, 'batch reopens on the first retry');
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobJoin')->state, 'still doomed behind u2');

        $operator->retry($this->member($batch, 'JobU2'), 'retry u2', 'op-1');
        $this->assertSame(JobState::Pending, $this->member($batch, 'JobJoin')->state, 'reachable again once both are back');
    }

    public function test_an_operator_canceled_member_is_not_revived_by_an_upstream_retry(): void
    {
        // Revival only undoes the system's own unreachable-cascade. A member
        // the operator canceled deliberately stays canceled.
        $batch = $this->jobwarden()->batch('chain', 'continue')
            ->add('a', 'JobA')
            ->add('b', 'JobB', dependsOn: ['a'])
            ->dispatch();

        $operator = $this->app->make(OperatorActions::class);
        $operator->cancel($this->member($batch, 'JobB'), 'operator does not want b', 'op-1');

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);

        $operator->retry($this->member($batch, 'JobA'), 'retry a', 'op-1');

        $this->assertSame(BatchState::Running, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state, 'operator verdict stands');

        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state, 'completes partial around the canceled member');
    }

    public function test_retrying_the_only_failure_of_a_fail_fast_batch_reopens_it(): void
    {
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $this->assertSame(BatchState::Failed, $batch->refresh()->state);
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);

        // With the lone failure back in flight the policy no longer trips, so
        // the batch reopens. b was canceled by the fail_fast sweep (not the
        // unreachable-cascade) and stays canceled — the batch ends partial.
        $this->app->make(OperatorActions::class)->retry($this->member($batch, 'JobA'), 'retry a', 'op-1');
        $this->assertSame(BatchState::Running, $batch->refresh()->state);

        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);
        $this->assertSame(BatchState::Partial, $batch->refresh()->state);
    }

    public function test_reopening_withdraws_the_eager_fail_flag_from_a_still_running_member(): void
    {
        // When fail_fast trips, a running member is only FLAGGED (its
        // supervisor honors the flag later). If the retry lands first, the
        // reopened batch must disarm that flag or the supervisor would kill a
        // healthy member of a batch that is running again.
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        $sm = $this->app->make(StateMachine::class);
        $sm->applyJobTransition($this->member($batch, 'JobB'), JobState::Running, TransitionContext::for(ActorType::Worker));

        $this->complete($this->member($batch, 'JobA'), JobState::Failed);
        $batch->refresh();
        $this->assertSame(BatchState::Failed, $batch->state);
        $b = $this->member($batch, 'JobB');
        $this->assertSame(JobState::Running, $b->state, 'running member is flagged, not transitioned');
        $this->assertTrue((bool) $b->cancel_requested);

        $this->app->make(OperatorActions::class)->retry($this->member($batch, 'JobA'), 'retry a', 'op-1');

        $this->assertSame(BatchState::Running, $batch->refresh()->state);
        $b = $this->member($batch, 'JobB');
        $this->assertFalse((bool) $b->cancel_requested, 'stale eager-fail flag withdrawn');
        $this->assertNull($b->cancel_reason);
    }

    public function test_cancel_batch_propagates_to_all_non_terminal_members(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        // a is already done; b is still queued.
        $this->complete($this->member($batch, 'JobA'), JobState::Succeeded);

        $this->jobwarden()->cancelBatch($batch, 'operator changed their mind', 'op-1');

        $batch->refresh();
        $this->assertSame(BatchState::Canceled, $batch->state);
        $this->assertSame(JobState::Succeeded, $this->member($batch, 'JobA')->state, 'already-terminal member untouched');
        $this->assertSame(JobState::Canceled, $this->member($batch, 'JobB')->state);
    }

    public function test_a_staggered_member_starts_pending_and_is_admitted_only_once_due(): void
    {
        // The Horizon `->delay(now()->addMinutes($i))` stagger: a per-member
        // available_at in the future must NOT start a dep-free root `queued`
        // (claimable now). It starts `pending`; the admit pass promotes it only
        // once available_at is reached. Undelayed roots stay immediately claimable.
        $t0 = Carbon::parse('2026-06-30 12:00:00');
        Carbon::setTestNow($t0);

        try {
            $batch = $this->jobwarden()->batch('staggered', 'continue')
                ->add('now', 'JobNow')                                              // no delay → queued
                ->add('soon', 'JobSoon', options: ['available_at' => $t0->copy()->addMinutes(5)])
                ->dispatch();

            $this->assertSame(JobState::Queued, $this->member($batch, 'JobNow')->state, 'undelayed root is claimable now');
            $this->assertSame(JobState::Pending, $this->member($batch, 'JobSoon')->state, 'delayed root waits');

            // Both are in-flight (Pending and Queued share the pending_count bucket).
            $batch->refresh();
            $this->assertSame(2, $batch->pending_count);
            $this->assertSame(BatchState::Running, $batch->state);

            $admitter = $this->app->make(Admitter::class);

            // Before the delay elapses the admit pass leaves it pending.
            Carbon::setTestNow($t0->copy()->addMinutes(4));
            $admitter->admit();
            $this->assertSame(JobState::Pending, $this->member($batch, 'JobSoon')->state, 'not promoted before available_at');

            // Once available_at passes it is admitted to queued.
            Carbon::setTestNow($t0->copy()->addMinutes(6));
            $admitter->admit();
            $this->assertSame(JobState::Queued, $this->member($batch, 'JobSoon')->state, 'admitted once available_at passed');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admit_window_is_not_starved_by_a_dependency_blocked_backlog(): void
    {
        // Regression: the admit pass takes the LIMIT earliest-available pending
        // rows. Without a dependency filter in that window query, a long chain's
        // blocked members (earliest available_at) filled the whole window and a
        // later chain's dep-satisfied successor was never evaluated — production
        // ran a 20-chain backfill ~2 chains at a time.
        $t0 = Carbon::parse('2026-06-30 12:00:00');

        try {
            Carbon::setTestNow($t0);
            $long = $this->jobwarden()->batch('long-chain')
                ->add('a0', 'JobA0')
                ->add('a1', 'JobA1', dependsOn: ['a0'])
                ->add('a2', 'JobA2', dependsOn: ['a1'])
                ->add('a3', 'JobA3', dependsOn: ['a2'])
                ->add('a4', 'JobA4', dependsOn: ['a3'])
                ->add('a5', 'JobA5', dependsOn: ['a4'])
                ->dispatch();

            Carbon::setTestNow($t0->copy()->addSeconds(10));
            $late = $this->jobwarden()->batch('late-chain')
                ->add('b0', 'JobB0')
                ->add('b1', 'JobB1', dependsOn: ['b0'])
                ->dispatch();

            $this->complete($this->member($long, 'JobA0'), JobState::Succeeded);
            $this->complete($this->member($late, 'JobB0'), JobState::Succeeded);

            // limit 5 = exactly the long chain's five pending members; b1 sorts
            // after every one of them by available_at.
            $this->app->make(Admitter::class)->admit(limit: 5);

            $this->assertSame(JobState::Queued, $this->member($long, 'JobA1')->state, 'long chain advances');
            $this->assertSame(JobState::Queued, $this->member($late, 'JobB1')->state, 'late chain is not starved by the blocked backlog');
            $this->assertSame(JobState::Pending, $this->member($long, 'JobA2')->state, 'still dep-blocked');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_dependency_cycle_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $this->jobwarden()->batch('cyclic')
            ->add('a', 'JobA', dependsOn: ['b'])
            ->add('b', 'JobB', dependsOn: ['a'])
            ->dispatch();
    }

    // -- helpers -----------------------------------------------------------

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
