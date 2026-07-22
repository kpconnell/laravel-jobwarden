<?php

declare(strict_types=1);

namespace JobWarden\Tests\Runner;

use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Runner\JobContext;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

/**
 * JobContext::batch() — what a finalizer (a member joined by a
 * dependsOnCompletion edge) can see about the batch it is reacting to. Running
 * after failure is only useful if the handler can tell WHAT failed.
 */
final class JobContextBatchTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_a_finalizer_sees_the_counts_and_the_members_that_did_not_succeed(): void
    {
        $batch = $this->jobwarden()->batch('etl', 'fail_fast')
            ->add('work', 'JobWork')
            ->add('other', 'JobOther')
            ->add('cleanup', 'JobCleanup', dependsOnCompletion: ['work'])
            ->dispatch();

        $work = $this->member($batch, 'JobWork');
        $this->complete($work, JobState::Failed);
        // The child records the throw site on the job row (ChildRunner::recordError).
        $work->refresh()->forceFill(['last_error' => ['class' => 'RuntimeException', 'message' => 'stripe timed out']])->saveQuietly();

        $cleanup = $this->member($batch, 'JobCleanup');
        $view = (new JobContext($cleanup->id, 'attempt-1', 1, null, (string) $cleanup->batch_id))->batch();

        $this->assertSame((string) $batch->id, $view['id']);
        $this->assertSame('etl', $view['name']);
        $this->assertSame('failed', $view['state'], 'the finalizer can see the verdict it is running under');
        $this->assertSame('fail_fast', $view['failure_policy']);

        $this->assertSame(3, $view['counts']['total']);
        $this->assertSame(1, $view['counts']['failed']);
        $this->assertSame(1, $view['counts']['canceled'], 'the member the eager sweep canceled');
        $this->assertSame(1, $view['counts']['pending'], 'the finalizer itself is still a member in flight');

        $this->assertCount(2, $view['failures'], 'both non-succeeding members, whatever their terminal state');
        $byClass = array_column($view['failures'], null, 'job_class');
        $this->assertSame('failed', $byClass['JobWork']['state']);
        $this->assertSame('stripe timed out', $byClass['JobWork']['error'], 'the failure message, from last_error');
        $this->assertSame('canceled', $byClass['JobOther']['state']);
        $this->assertSame('batch member failed under fail_fast', $byClass['JobOther']['error'], 'the cancel verdict stands in for an error');
    }

    public function test_batch_is_null_for_a_standalone_job(): void
    {
        $this->assertNull((new JobContext('j', 'a', 1))->batch());
    }

    // -- helpers -----------------------------------------------------------

    private function jobwarden(): JobWarden
    {
        return $this->app->make(JobWarden::class);
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
