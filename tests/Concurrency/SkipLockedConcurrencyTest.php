<?php

declare(strict_types=1);

namespace JobWarden\Tests\Concurrency;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;

/**
 * The claim path's correctness proof. Two complementary tiers:
 *
 *  Tier A — a deterministic, zero-flake 2-connection proof that the lock clause
 *           is honored: one session holds a row FOR UPDATE SKIP LOCKED, a second
 *           session must skip it.
 *  Tier B — an N-process fan-out stress: real OS processes race to drain a pool
 *           of queued jobs; we assert zero double-claims.
 *
 * Runs on every SKIP-LOCKED engine — Postgres AND MariaDB/MySQL (the first
 * production target). SQLite is single-writer and is skipped.
 */
final class SkipLockedConcurrencyTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->engine(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('SKIP LOCKED concurrency requires Postgres or MySQL/MariaDB.');
        }

        $this->setUpJobWardenSchema();
    }

    public function test_tier_a_second_session_skips_a_locked_row(): void
    {
        $a = $this->seedQueued(priority: 10);
        $b = $this->seedQueued(priority: 5);

        // Second, independent session to the same database.
        config(['database.connections.jobwarden2' => config('database.connections.jobwarden')]);
        $conn1 = DB::connection('jobwarden');
        $conn2 = DB::connection('jobwarden2');

        try {
            $conn1->beginTransaction();

            $locked = $conn1->table($this->tbl('jobs'))
                ->where('lane', 'default')->where('state', 'queued')->orderByDesc('priority')
                ->limit(1)->lock('for update skip locked')->value('id');
            $this->assertSame($a->id, $locked, 'session 1 locks the highest-priority row');

            // Session 2 must SKIP the locked row and get the other one.
            $other = $conn2->table($this->tbl('jobs'))
                ->where('lane', 'default')->where('state', 'queued')->orderByDesc('priority')
                ->limit(1)->lock('for update skip locked')->value('id');
            $this->assertSame($b->id, $other, 'session 2 skips the locked row');
        } finally {
            $conn1->rollBack();
            $conn2->disconnect();
            DB::purge('jobwarden2');
        }
    }

    public function test_tier_a_single_row_is_never_double_claimed(): void
    {
        $only = $this->seedQueued();

        config(['database.connections.jobwarden2' => config('database.connections.jobwarden')]);
        $conn1 = DB::connection('jobwarden');
        $conn2 = DB::connection('jobwarden2');

        try {
            $conn1->beginTransaction();
            $conn1->table($this->tbl('jobs'))->where('lane', 'default')->where('state', 'queued')
                ->limit(1)->lock('for update skip locked')->value('id');

            // The only queued row is locked → session 2 gets nothing (no double claim).
            $second = $conn2->table($this->tbl('jobs'))->where('lane', 'default')->where('state', 'queued')
                ->limit(1)->lock('for update skip locked')->value('id');
            $this->assertNull($second);
        } finally {
            $conn1->rollBack();
            $conn2->disconnect();
            DB::purge('jobwarden2');
        }
    }

    public function test_tier_b_n_processes_never_double_claim(): void
    {
        $m = 100;  // jobs in the pool
        $n = 50;  // racing processes

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
            'JOBWARDEN_BARRIER' => (string) (microtime(true) + 1.0), // shared start instant
        ];

        $script = realpath(__DIR__.'/../bin/claimer.php');
        $this->assertNotFalse($script, 'claimer script must exist');

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

        // Every job claimed exactly once: no duplicates, full coverage.
        $this->assertCount($m, $claimedIds, 'every job should be claimed exactly once');
        $this->assertCount($m, array_unique($claimedIds), 'NO job was claimed twice');

        // And the database agrees: M running jobs, M attempts, attempt_count == 1.
        $this->assertSame(0, Job::where('state', JobState::Queued->value)->count());
        $this->assertSame($m, Job::where('state', JobState::Running->value)->count());
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
