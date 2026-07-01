<?php

declare(strict_types=1);

namespace JobWarden\Tests\Reaper;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Models\Worker;
use JobWarden\Reaper\GlobalReaper;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Tier 3, made deterministic by seeding a stale host lease + in-flight attempts
 * (no real host death). Proves: a lost host's attempts are orphaned BY ANOTHER
 * host's reaper, the fencing token is bumped, recovery re-queues idempotent
 * jobs, a non-idempotent job parks, and the action is audited in job_logs.
 */
final class GlobalReaperTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // Tight budget so the seeded heartbeat is "stale" immediately.
        config(['jobwarden.host_lease.heartbeat_interval' => 1, 'jobwarden.host_lease.missed_beats' => 2]);
        $this->setUpJobWardenSchema();
    }

    private function reaper(): GlobalReaper
    {
        return $this->app->make(GlobalReaper::class);
    }

    public function test_a_lost_workers_running_attempt_is_orphaned_and_recovered_by_another_host(): void
    {
        $dead = $this->seedDeadWorker('host-DEAD', staleSeconds: 30);
        [$job, $attempt] = $this->seedRunningOn($dead, idempotent: true, maxAttempts: 3, token: 1);

        // Another host's reaper scans.
        $wasLeader = $this->reaper()->tick('reaper-on-host2');
        $this->assertTrue($wasLeader);

        // The dead worker is marked dead.
        $this->assertSame('dead', Worker::find($dead->id)->state);

        // The attempt is orphaned and fenced (epoch bumped 1 → 2).
        $attempt = JobAttempt::find($attempt->id);
        $this->assertSame(AttemptState::Orphaned, $attempt->state);
        $this->assertSame(2, $attempt->fencing_token);

        // The idempotent job was recovered → retrying (a fresh attempt will be
        // minted on the next claim, possibly on another host).
        $job = Job::find($job->id);
        $this->assertSame(JobState::Retrying, $job->state);

        // The reaper's action is recorded in the job's own log, keyed on the
        // dead worker (the per-process identity), not the host.
        $reapLog = JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->first();
        $this->assertNotNull($reapLog);
        $this->assertStringContainsString((string) $dead->id, (string) $reapLog->body_ref);
        $this->assertSame('global_reaper', $reapLog->context['actor'] ?? null);
    }

    public function test_a_restarted_worker_under_the_same_host_does_not_mask_the_dead_one(): void
    {
        // THE stress-test bug: a process restarts on the same box under the same
        // (boot-stable) host_id, and its fresh heartbeat used to mask the dead
        // incarnation because detection grouped by host_id and took MAX(beat).
        // Worker-keyed detection must reap the dead worker's attempt anyway.
        $host = 'host-SHARED';
        $dead = $this->seedDeadWorker($host, staleSeconds: 30);
        [$job, $attempt] = $this->seedRunningOn($dead, idempotent: true, maxAttempts: 3, token: 1);

        // The restarted incarnation: SAME host_id, FRESH heartbeat, new worker_id.
        $live = $this->seedDeadWorker($host, staleSeconds: 0);

        $this->reaper()->tick('reaper-x');

        // The dead incarnation's attempt is orphaned despite the live same-host peer.
        $this->assertSame(AttemptState::Orphaned, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);
        // Only the dead worker is reaped; the fresh incarnation stays active.
        $this->assertSame('dead', Worker::find($dead->id)->state);
        $this->assertSame('active', Worker::find($live->id)->state);
    }

    public function test_a_non_idempotent_lost_job_parks_in_orphaned(): void
    {
        $dead = $this->seedDeadWorker('host-DEAD-2', staleSeconds: 30);
        [$job, $attempt] = $this->seedRunningOn($dead, idempotent: false, maxAttempts: 1, token: 1);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(AttemptState::Orphaned, JobAttempt::find($attempt->id)->state);
        // Non-idempotent → parked in orphaned for an operator (default policy).
        $this->assertSame(JobState::Orphaned, Job::find($job->id)->state);
    }

    public function test_a_live_worker_is_not_reaped(): void
    {
        $live = $this->seedDeadWorker('host-LIVE', staleSeconds: 0); // fresh heartbeat
        [$job, $attempt] = $this->seedRunningOn($live, idempotent: true, maxAttempts: 3, token: 1);

        $this->reaper()->tick('reaper-x');

        $this->assertSame(AttemptState::Running, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Running, Job::find($job->id)->state);
    }

    private function seedDeadWorker(string $hostId, int $staleSeconds): Worker
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
            'heartbeat_at' => Carbon::now()->subSeconds($staleSeconds),
        ]);
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function seedRunningOn(Worker $worker, bool $idempotent, int $maxAttempts, int $token): array
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => $idempotent,
            'max_attempts' => $maxAttempts,
            'attempt_count' => 1,
            'backoff_strategy' => 'fixed',
        ]);

        // The claim stamps the attempt with the CLAIMING WORKER's id (the real
        // path) — that per-process id is what Tier 3 keys recovery on.
        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Running,
            'fencing_token' => $token,
            'worker_id' => $worker->id,
            'host_id' => $worker->host_id,
            'child_pid' => 9999,
            'child_start_time' => 123,
        ]);

        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        return [$job, $attempt];
    }
}
