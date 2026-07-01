<?php

declare(strict_types=1);

namespace JobWarden\Tests\Claim;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Claim\Contracts\ClaimDriver;
use JobWarden\Claim\EngineInspector;
use JobWarden\Claim\WorkerContext;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\Worker;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class ClaimDriverTest extends TestCase
{
    use RefreshesJobWardenSchema;

    private WorkerContext $workerContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();

        // A real supervisor worker row (worker_id is a uuid FK → workers).
        $worker = Worker::create([
            'role' => 'supervisor',
            'host_id' => 'host-abc',
            'hostname' => 'host-abc.local',
            'pid' => 4321,
            'incarnation' => 1,
            'state' => 'active',
            'capacity' => 5,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $this->workerContext = new WorkerContext($worker->id, 'host-abc', 'host-abc.local', 4321, 99887766);
    }

    private function driver(): ClaimDriver
    {
        return $this->app->make(ClaimDriverFactory::class)->make();
    }

    private function worker(): WorkerContext
    {
        return $this->workerContext;
    }

    public function test_engine_inspector_reports_skip_locked_capability(): void
    {
        $inspector = new EngineInspector(DB::connection(config('jobwarden.connection')));

        // Our harness runs Postgres 18 and MariaDB 11.4 (both ≥ the SKIP LOCKED
        // floor); only SQLite lacks it.
        $expected = match ($this->engine()) {
            'pgsql', 'mysql' => true,
            default => false,
        };

        $this->assertSame($expected, $inspector->supportsSkipLocked());
    }

    public function test_claim_mints_a_dispatched_attempt_with_the_phase1_stamp_and_runs_the_job(): void
    {
        $job = $this->seedQueued(priority: 0);

        $claimed = $this->driver()->claim($this->worker(), 1);

        $this->assertCount(1, $claimed);
        $c = $claimed[0];
        $this->assertSame($job->id, $c->jobId);
        $this->assertSame(1, $c->attemptNumber);
        $this->assertSame(1, $c->fencingToken);

        $job->refresh();
        $this->assertSame(JobState::Running, $job->state);
        $this->assertSame(1, $job->attempt_count);
        $this->assertSame($c->attemptId, $job->current_attempt_id);

        $attempt = JobAttempt::find($c->attemptId);
        $this->assertSame(AttemptState::Dispatched, $attempt->state);
        $this->assertSame(1, $attempt->fencing_token);
        $this->assertSame('host-abc', $attempt->host_id);
        $this->assertSame(4321, $attempt->supervisor_pid);
        $this->assertSame(99887766, $attempt->supervisor_start_time);
        // child half of the stamp is not yet written (phase 2 / P5).
        $this->assertNull($attempt->child_pid);

        // The claim moved the job through the StateMachine (audited).
        $event = $this->jobwarden()->table($this->tbl('job_events'))
            ->where('job_id', $job->id)->where('to_state', 'running')->first();
        $this->assertNotNull($event);
        $this->assertSame('supervisor', $event->actor_type);
    }

    public function test_claim_respects_priority_then_age_and_does_not_double_claim(): void
    {
        $low = $this->seedQueued(priority: 0);
        $high = $this->seedQueued(priority: 10);
        $mid = $this->seedQueued(priority: 5);

        $first = $this->driver()->claim($this->worker(), 1);
        $this->assertSame($high->id, $first[0]->jobId, 'highest priority claimed first');

        $rest = $this->driver()->claim($this->worker(), 5);
        $this->assertCount(2, $rest);
        $this->assertSame([$mid->id, $low->id], array_map(fn ($c) => $c->jobId, $rest));

        // Nothing left, and no job was claimed twice.
        $this->assertSame([], $this->driver()->claim($this->worker(), 5));
        $this->assertSame(3, JobAttempt::count());
        $this->assertSame(0, Job::where('state', JobState::Queued->value)->count());
        foreach ([$low, $high, $mid] as $j) {
            $this->assertSame(1, Job::find($j->id)->attempt_count);
        }
    }

    public function test_claim_skips_jobs_not_yet_available(): void
    {
        $future = $this->seedQueued(priority: 0, availableAt: now()->addHour());
        $ready = $this->seedQueued(priority: 0, availableAt: now()->subMinute());

        $claimed = $this->driver()->claim($this->worker(), 5);

        $this->assertCount(1, $claimed);
        $this->assertSame($ready->id, $claimed[0]->jobId);
    }

    public function test_driver_emits_the_skip_locked_clause(): void
    {
        if ($this->engine() !== 'pgsql') {
            $this->markTestSkipped('SKIP LOCKED clause only emitted on capable engines.');
        }

        $this->seedQueued();

        $conn = DB::connection(config('jobwarden.connection'));
        $conn->enableQueryLog();
        $this->driver()->claim($this->worker(), 1);

        $select = collect($conn->getQueryLog())
            ->pluck('query')
            ->first(fn (string $q) => str_contains($q, 'select') && str_contains($q, $this->tbl('jobs')));

        $this->assertNotNull($select);
        $this->assertStringContainsString('for update skip locked', strtolower($select));
    }

    private function seedQueued(int $priority = 0, $availableAt = null): Job
    {
        return Job::create([
            'job_class' => 'App\\Jobs\\Demo',
            'state' => JobState::Queued,
            'priority' => $priority,
            'available_at' => $availableAt ?? now()->subSecond(),
            'max_attempts' => 3,
            'attempt_count' => 0,
        ]);
    }
}
