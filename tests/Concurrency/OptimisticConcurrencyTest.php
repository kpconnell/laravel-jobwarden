<?php

declare(strict_types=1);

namespace JobWarden\Tests\Concurrency;

use JobWarden\Claim\OptimisticClaimDriver;
use JobWarden\Claim\WorkerContext;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\StateMachine\StateMachine;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * The optimistic (lock-free) claim driver must give the SAME no-double-claim
 * guarantee as SKIP LOCKED — via the guarded UPDATE … WHERE state='queued'.
 */
final class OptimisticConcurrencyTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function driver(): OptimisticClaimDriver
    {
        return new OptimisticClaimDriver($this->app->make(StateMachine::class));
    }

    private function worker(): WorkerContext
    {
        // A real supervisor row — worker_id is a uuid FK → workers.
        $worker = \JobWarden\Models\Worker::create([
            'role' => 'supervisor',
            'host_id' => 'host-1',
            'hostname' => 'box',
            'pid' => 4242,
            'incarnation' => 1,
            'state' => 'active',
            'capacity' => 5,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        return new WorkerContext($worker->id, 'host-1', 'box', 4242, 999);
    }

    public function test_it_claims_a_queued_job_and_mints_a_dispatched_attempt(): void
    {
        $job = $this->seedQueued();

        $claimed = $this->driver()->claim($this->worker(), 5);

        $this->assertCount(1, $claimed);
        $this->assertSame($job->id, $claimed[0]->jobId);
        $this->assertSame(JobState::Running, Job::find($job->id)->state);
        $this->assertSame(1, JobAttempt::where('job_id', $job->id)->count());
        $this->assertSame(AttemptState::Dispatched, JobAttempt::where('job_id', $job->id)->value('state'));

        // Nothing left to claim.
        $this->assertCount(0, $this->driver()->claim($this->worker(), 5));
    }

    public function test_it_drains_a_pool_in_priority_then_age_order(): void
    {
        $lo = $this->seedQueued(priority: 1);
        $hi = $this->seedQueued(priority: 9);

        $claimed = $this->driver()->claim($this->worker(), 2);

        $this->assertSame([$hi->id, $lo->id], array_map(fn ($c) => $c->jobId, $claimed));
    }

    public function test_n_processes_never_double_claim_with_the_optimistic_driver(): void
    {
        if (! in_array($this->engine(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('Real concurrency requires Postgres or MySQL/MariaDB.');
        }

        $m = 80;  // jobs
        $n = 40;  // racing processes
        for ($i = 0; $i < $m; $i++) {
            $this->seedQueued();
        }

        $db = config('database.connections.jobwarden');
        $env = [
            'JOBWARDEN_DRIVER' => (string) $db['driver'],
            'JOBWARDEN_DB_HOST' => (string) $db['host'],
            'JOBWARDEN_DB_PORT' => (string) $db['port'],
            'JOBWARDEN_DB_NAME' => (string) $db['database'],
            'JOBWARDEN_DB_USER' => (string) $db['username'],
            'JOBWARDEN_DB_PASSWORD' => (string) $db['password'],
            'JOBWARDEN_PREFIX' => (string) config('jobwarden.table_prefix'),
            'JOBWARDEN_BARRIER' => (string) (microtime(true) + 1.0),
        ];

        $script = realpath(__DIR__.'/../bin/claimer_optimistic.php');
        $this->assertNotFalse($script);

        /** @var Process[] $procs */
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $p = new Process(['php', $script], null, $env);
            $p->start();
            $procs[] = $p;
        }

        $claimedIds = [];
        foreach ($procs as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), 'claimer failed: '.$p->getErrorOutput());
            foreach (preg_split('/\R/', trim($p->getOutput())) ?: [] as $line) {
                if ($line !== '') {
                    $claimedIds[] = $line;
                }
            }
        }

        $this->assertCount($m, $claimedIds, 'every job claimed exactly once');
        $this->assertCount($m, array_unique($claimedIds), 'NO job was claimed twice');
        $this->assertSame(0, Job::where('state', JobState::Queued->value)->count());
        $this->assertSame($m, JobAttempt::count());
        $this->assertSame(0, Job::where('attempt_count', '!=', 1)->count());
    }

    private function seedQueued(int $priority = 0): Job
    {
        return Job::create([
            'job_class' => 'App\\Jobs\\Demo',
            'state' => JobState::Queued,
            'priority' => $priority,
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
            'attempt_count' => 0,
        ]);
    }
}
