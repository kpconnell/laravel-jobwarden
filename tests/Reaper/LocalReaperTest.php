<?php

declare(strict_types=1);

namespace JobWarden\Tests\Reaper;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Fake\FakeHostIdentity;
use JobWarden\Process\Fake\FakeProbe;
use JobWarden\Reaper\LocalReaper;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

/**
 * Tier 2 made deterministic with a FakeProbe (no real reparenting): the local
 * reaper kills a dead supervisor's reparented child and orphans the attempt;
 * leaves a healthy attempt alone; and self-fences on lost connectivity.
 */
final class LocalReaperTest extends TestCase
{
    use RefreshesJobWardenSchema;

    private FakeProbe $probe;

    private string $host = 'fake-host';

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobwarden.supervisor.graceful_timeout' => 1]);

        $this->probe = new FakeProbe;
        $this->app->instance(ProcessProbe::class, $this->probe);
        $this->app->instance(HostIdentity::class, new FakeHostIdentity($this->host));

        $this->setUpJobWardenSchema();
    }

    private function reaper(): LocalReaper
    {
        $reaper = $this->app->make(LocalReaper::class);
        $reaper->boot();

        return $reaper;
    }

    public function test_kills_a_reparented_child_of_a_dead_supervisor_then_orphans(): void
    {
        // supervisor pid 100 is DEAD (never spawned); child pid 200 is ALIVE,
        // reparented to init.
        $this->probe->spawn(pid: 200, startTime: '5000', ppid: 1, attemptId: 'will-be-set', nonce: 'n');
        [$job, $attempt] = $this->seedRunning(idempotent: true, supPid: 100, childPid: 200, childStart: 5000);

        $this->reaper()->scan();

        // The reparented child was SIGKILLed (after SIGTERM was ignored).
        $this->assertContains(FakeProbe::SIGKILL, $this->probe->signalsSentTo(200));
        $this->assertFalse($this->probe->pidAlive(200), 'child must be confirmed dead before orphaning');

        $attempt = JobAttempt::find($attempt->id);
        $this->assertSame(AttemptState::Orphaned, $attempt->state);
        $this->assertSame(2, $attempt->fencing_token, 'orphan bumps the fence');

        $this->assertSame(JobState::Retrying, Job::find($job->id)->state);

        $reapLog = JobLog::where('attempt_id', $attempt->id)->where('step', 'reaped')->first();
        $this->assertNotNull($reapLog);
        $this->assertSame('local_reaper', $reapLog->context['actor'] ?? null);
        $this->assertStringContainsString('reparented', (string) $reapLog->body_ref);
    }

    public function test_leaves_a_healthy_attempt_alone(): void
    {
        $this->probe->spawn(pid: 100, startTime: '999');   // supervisor alive
        $this->probe->spawn(pid: 200, startTime: '5000');  // child alive
        [$job, $attempt] = $this->seedRunning(idempotent: true, supPid: 100, childPid: 200, childStart: 5000);

        $this->reaper()->scan();

        $this->assertSame(AttemptState::Running, JobAttempt::find($attempt->id)->state);
        $this->assertSame([], $this->probe->signalsSentTo(200), 'a healthy child is never signalled');
    }

    public function test_orphans_when_supervisor_and_child_are_both_gone(): void
    {
        // nothing spawned → both dead
        [$job, $attempt] = $this->seedRunning(idempotent: false, supPid: 100, childPid: 200, childStart: 5000);

        $this->reaper()->scan();

        $this->assertSame(AttemptState::Orphaned, JobAttempt::find($attempt->id)->state);
        // non-idempotent → parked in orphaned
        $this->assertSame(JobState::Orphaned, Job::find($job->id)->state);
    }

    public function test_self_fence_kills_stamped_children(): void
    {
        $this->probe->spawn(pid: 200, startTime: '5000');
        $this->seedRunning(idempotent: true, supPid: 100, childPid: 200, childStart: 5000);

        $reaper = $this->reaper();
        $reaper->selfFence();

        $this->assertTrue($reaper->fenced());
        $this->assertContains(FakeProbe::SIGKILL, $this->probe->signalsSentTo(200));
        $this->assertFalse($this->probe->pidAlive(200));
    }

    /** @return array{0: Job, 1: JobAttempt} */
    private function seedRunning(bool $idempotent, int $supPid, int $childPid, int $childStart): array
    {
        $job = Job::create([
            'job_class' => 'X',
            'state' => JobState::Running,
            'idempotent' => $idempotent,
            'max_attempts' => 3,
            'attempt_count' => 1,
            'backoff_strategy' => 'fixed',
        ]);

        $attempt = JobAttempt::create([
            'job_id' => $job->id,
            'attempt_number' => 1,
            'state' => AttemptState::Running,
            'fencing_token' => 1,
            'host_id' => $this->host,
            'supervisor_pid' => $supPid,
            'supervisor_start_time' => 999,
            'child_pid' => $childPid,
            'child_start_time' => $childStart,
            'proc_nonce' => 'n',
        ]);

        $job->forceFill(['current_attempt_id' => $attempt->id])->saveQuietly();

        return [$job, $attempt];
    }
}
