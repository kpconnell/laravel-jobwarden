<?php

declare(strict_types=1);

namespace JobWarden\Tests\Reaper;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Models\Worker;
use JobWarden\Reaper\AttemptOrphaner;
use JobWarden\Reaper\GlobalReaper;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

final class ReaperReliabilityTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jobwarden.host_lease.heartbeat_interval' => 1,
            'jobwarden.host_lease.missed_beats' => 2,
            'jobwarden.retry.backoff.strategy' => 'fixed',
            'jobwarden.retry.backoff.base' => 1,
            'jobwarden.retry.backoff.cap' => 1,
        ]);

        $this->setUpJobWardenSchema();
    }

    public function test_global_reaper_is_idempotent_across_repeated_ticks_and_rejects_late_worker_writes(): void
    {
        $worker = $this->seedStaleWorker('dead-host-repeat');
        [$job, $attempt] = $this->seedRunningAttempt(idempotent: true, worker: $worker);

        // A stale worker view from before the host was declared dead.
        $zombie = JobAttempt::find($attempt->id);

        $reaper = $this->app->make(GlobalReaper::class);

        $this->assertTrue($reaper->tick('global-reaper-a'));
        $this->assertTrue($reaper->tick('global-reaper-a'), 'the same leader may refresh and tick again');

        $attempt = JobAttempt::find($attempt->id);
        $this->assertSame(AttemptState::Orphaned, $attempt->state);
        $this->assertSame(2, $attempt->fencing_token, 'the fence is bumped exactly once');

        $job = Job::find($job->id);
        $this->assertSame(JobState::Retrying, $job->state);
        $this->assertSame(1, $job->attempt_count, 'recovery schedules a retry but does not mint a duplicate attempt');

        $this->assertSame(1, JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->count());
        $this->assertSame(1, $this->events($attempt->id, 'attempt', 'running', 'orphaned'));
        $this->assertSame(1, $this->events($attempt->job_id, 'job', 'running', 'orphaned'));
        $this->assertSame(1, $this->events($attempt->job_id, 'job', 'orphaned', 'retrying'));

        try {
            $this->app->make(StateMachine::class)->applyAttemptTransition(
                $zombie,
                AttemptState::Succeeded,
                TransitionContext::for(ActorType::Worker, 'zombie-worker', 'late success')
                    ->expectingToken(1)
            );
            $this->fail('expected stale worker report to be rejected');
        } catch (StaleFencingTokenException) {
            // expected
        }

        $attempt = JobAttempt::find($attempt->id);
        $this->assertSame(AttemptState::Orphaned, $attempt->state);
        $this->assertSame(2, $attempt->fencing_token);
        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);
        $this->assertSame(1, $this->jobwarden()->table($this->tbl('job_events'))
            ->where('attempt_id', $attempt->id)
            ->where('reason', 'like', 'stale_write_rejected%')
            ->count());
    }

    public function test_duplicate_orphan_requests_do_not_double_bump_or_double_recover(): void
    {
        $hostId = 'dead-host-race';
        [$job, $attempt] = $this->seedRunningAttempt(idempotent: true, hostId: $hostId);
        $stalePeerView = JobAttempt::find($attempt->id);

        $orphaner = $this->app->make(AttemptOrphaner::class);

        $this->assertTrue($orphaner->orphan($attempt, 'reaper-1', $hostId, 'global', 'host dead'));
        $this->assertFalse($orphaner->orphan($stalePeerView, 'reaper-2', $hostId, 'local', 'same host already handled'));

        $attempt = JobAttempt::find($attempt->id);
        $job = Job::find($job->id);

        $this->assertSame(AttemptState::Orphaned, $attempt->state);
        $this->assertSame(2, $attempt->fencing_token);
        $this->assertSame(JobState::Retrying, $job->state);

        $this->assertSame(1, JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->count());
        $this->assertSame(1, $this->events($attempt->id, 'attempt', 'running', 'orphaned'));
        $this->assertSame(1, $this->events($job->id, 'job', 'running', 'orphaned'));
        $this->assertSame(1, $this->events($job->id, 'job', 'orphaned', 'retrying'));
    }

    private function seedStaleWorker(string $hostId): Worker
    {
        return Worker::create([
            'role' => 'supervisor',
            'host_id' => $hostId,
            'hostname' => $hostId,
            'pid' => 1234,
            'incarnation' => 1,
            'state' => 'active',
            'capacity' => 5,
            'started_at' => Carbon::now()->subMinutes(10),
            'heartbeat_at' => Carbon::now()->subSeconds(30),
        ]);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function seedRunningAttempt(bool $idempotent, ?Worker $worker = null, string $hostId = 'unassigned'): array
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => $idempotent,
            'max_attempts' => 3,
            'attempt_count' => 1,
            'backoff_strategy' => 'fixed',
        ]);

        // Stamp the claiming worker's id when one is given (Tier-3 keys on it);
        // the direct-orphan test needs no worker, only a host label.
        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Running,
            'fencing_token' => 1,
            'worker_id' => $worker?->id,
            'host_id' => $worker->host_id ?? $hostId,
            'child_pid' => 9999,
            'child_start_time' => 123,
        ]);

        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        return [$job, $attempt];
    }

    private function events(string $id, string $level, string $from, string $to): int
    {
        $query = $this->jobwarden()->table($this->tbl('job_events'))
            ->where('level', $level)
            ->where('from_state', $from)
            ->where('to_state', $to);

        if ($level === 'attempt') {
            $query->where('attempt_id', $id);
        } else {
            $query->where('job_id', $id);
        }

        return (int) $query->count();
    }
}
