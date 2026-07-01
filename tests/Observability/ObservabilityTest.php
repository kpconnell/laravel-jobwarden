<?php

declare(strict_types=1);

namespace JobWarden\Tests\Observability;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobEvent;
use JobWarden\Models\JobLog;
use JobWarden\Models\Worker;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

final class ObservabilityTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_read_model_scopes(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Running, 'started_at' => now()]);
        Job::create(['job_class' => 'X', 'state' => JobState::Queued]);
        Job::create(['job_class' => 'X', 'state' => JobState::Pending]);
        Job::create(['job_class' => 'X', 'state' => JobState::Failed]);
        Job::create(['job_class' => 'X', 'state' => JobState::Orphaned]);
        // stuck: running, past its runtime ceiling.
        Job::create(['job_class' => 'X', 'state' => JobState::Running, 'max_runtime_sec' => 10, 'started_at' => now()->subMinute()]);

        $this->assertSame(2, Job::running()->count());
        $this->assertSame(2, Job::waiting()->count()); // queued + pending
        $this->assertSame(1, Job::failed()->count());
        $this->assertSame(1, Job::orphaned()->count());
        $this->assertSame(1, Job::stuck()->count());
    }

    public function test_prune_deletes_old_terminal_jobs_with_their_children_and_keeps_recent(): void
    {
        config(['jobwarden.retention.jobs_days' => 7, 'jobwarden.retention.workers_days' => 7]);

        $old = $this->terminalJob(finishedDaysAgo: 10);
        $this->seedChildren($old);
        $recent = $this->terminalJob(finishedDaysAgo: 1);
        $running = Job::create(['job_class' => 'X', 'state' => JobState::Running, 'started_at' => now()]);

        $deadWorker = Worker::create(['role' => 'supervisor', 'host_id' => 'h', 'state' => 'dead', 'started_at' => now()->subDays(20), 'heartbeat_at' => now()->subDays(20), 'stopped_at' => now()->subDays(10)]);
        $liveWorker = Worker::create(['role' => 'supervisor', 'host_id' => 'h', 'state' => 'active', 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->artisan('jobwarden:prune')->assertExitCode(0);

        // Old terminal job gone, plus its cascaded children.
        $this->assertNull(Job::find($old->id));
        $this->assertSame(0, JobAttempt::where('job_id', $old->id)->count());
        $this->assertSame(0, JobEvent::where('job_id', $old->id)->count());
        $this->assertSame(0, JobLog::where('job_id', $old->id)->count());

        // Recent + running kept.
        $this->assertNotNull(Job::find($recent->id));
        $this->assertNotNull(Job::find($running->id));

        // Dead worker pruned, live worker kept.
        $this->assertNull(Worker::find($deadWorker->id));
        $this->assertNotNull(Worker::find($liveWorker->id));
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        config(['jobwarden.retention.jobs_days' => 7]);
        $old = $this->terminalJob(finishedDaysAgo: 30);

        $this->artisan('jobwarden:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(Job::find($old->id), 'dry-run must not delete');
    }

    public function test_workers_command_runs(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h', 'state' => 'active', 'pid' => 1, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->artisan('jobwarden:workers')->assertExitCode(0);
    }

    private function terminalJob(int $finishedDaysAgo): Job
    {
        return Job::create([
            'job_class' => 'X',
            'state' => JobState::Succeeded,
            'finished_at' => Carbon::now()->subDays($finishedDaysAgo),
        ]);
    }

    private function seedChildren(Job $job): void
    {
        $attempt = JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Succeeded, 'fencing_token' => 1]);
        JobEvent::create(['job_id' => $job->id, 'level' => 'job', 'to_state' => 'succeeded', 'actor_type' => 'worker', 'created_at' => now()]);
        JobLog::create(['job_id' => $job->id, 'attempt_id' => $attempt->id, 'seq' => 1, 'ts' => now(), 'level' => 'info', 'body_sink' => 'database', 'body_ref' => 'x']);
    }
}
