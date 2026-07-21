<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Workbench\App\Jobs\CrashJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\MarkerJob;

/**
 * End-to-end PREFORK execution (config execution_mode=prefork): the supervisor
 * pcntl_fork()s in-process per job instead of proc_open'ing a fresh PHP. Proves:
 *   1. the fork success path + that the fork self-writes its phase-2 stamp with its
 *      OWN pid (not the master's),
 *   2. the master's DB connection SURVIVES a fork storm — the COM_QUIT hazard where a
 *      child's graceful PDO close would tear down the shared socket (parent never
 *      discards; children hold-inherited-PDOs + pcntl_exec out),
 *   3. crash isolation — a forked child dying doesn't touch the master or its siblings.
 *
 * A fork can't share sqlite :memory:, so this needs a real MySQL/MariaDB or Postgres,
 * plus pcntl. Point it at an isolated DB (JOBWARDEN_DB_NAME), never a live fleet.
 */
final class PreforkE2ETest extends TestCase
{
    use RefreshesJobWardenSchema;

    private string $root;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->engine(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('prefork forks share the DB across processes — needs MySQL/MariaDB or Postgres, not sqlite :memory:.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('prefork requires the pcntl extension.');
        }

        $this->root = dirname(__DIR__, 2);
        $this->storage = $this->root.'/workbench/storage/jobwarden';
        @mkdir($this->storage.'/logs', 0775, true);

        config([
            'jobwarden.supervisor.execution_mode' => 'prefork',
            // The fork/waitpid/COM_QUIT mechanics under test are real on any POSIX host;
            // only the /proc-based liveness probe is Linux-only, so fake it so this runs
            // on a macOS dev box too (no assertion depends on the probe's metadata).
            'jobwarden.process.probe' => 'fake',
            'jobwarden.runtime_path' => $this->storage,
            'jobwarden.supervisor.graceful_timeout' => 2,
            'jobwarden.retry.backoff.strategy' => 'fixed',
            'jobwarden.retry.backoff.base' => 1,
            'jobwarden.retry.backoff.cap' => 2,
        ]);

        $this->setUpJobWardenSchema();
    }

    private function supervisor(int $capacity = 4): Supervisor
    {
        return new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
            $this->app->make(\JobWarden\Worker\WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(\JobWarden\Logging\JobLogger::class),
            $capacity,
        );
    }

    public function test_prefork_runs_a_job_to_success_in_a_real_fork(): void
    {
        $marker = $this->storage.'/marker-'.bin2hex(random_bytes(4)).'.txt';
        @unlink($marker);

        $job = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, ['marker' => $marker], ['idempotent' => true]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);

        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);
        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $this->assertSame(AttemptState::Succeeded, $attempt->state);
        $this->assertNotNull($attempt->child_pid, 'the fork self-wrote its phase-2 stamp');
        $this->assertNotSame(getmypid(), (int) $attempt->child_pid, 'ran in a distinct forked pid, not the master process');
        $this->assertStringStartsWith('done:', (string) @file_get_contents($marker));

        @unlink($marker);
    }

    public function test_master_db_connection_survives_a_fork_storm(): void
    {
        $n = 40;
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $marker = $this->storage.'/fs-'.$i.'.txt';
            @unlink($marker);
            $ids[] = $this->app->make(JobWarden::class)
                ->dispatch(MarkerJob::class, ['marker' => $marker], ['idempotent' => true])->id;
        }

        $supervisor = $this->supervisor(capacity: 4);
        $supervisor->boot();
        $this->driveUntil($supervisor, $ids, timeout: 40.0);

        $succeeded = Job::whereIn('id', $ids)->where('state', JobState::Succeeded->value)->count();
        $this->assertSame($n, $succeeded, 'every forked job reached Succeeded');

        // THE regression guard: the master's own connection is still alive after N forks.
        // If any child had gracefully closed the inherited PDO (COM_QUIT on the shared
        // socket), this query would throw "MySQL server has gone away".
        $this->assertSame(1, (int) DB::connection('jobwarden')->select('SELECT 1 AS x')[0]->x);

        for ($i = 0; $i < $n; $i++) {
            @unlink($this->storage.'/fs-'.$i.'.txt');
        }
    }

    public function test_prefork_isolates_a_child_crash_from_the_master_and_siblings(): void
    {
        $marker = $this->storage.'/sibling-'.bin2hex(random_bytes(4)).'.txt';
        @unlink($marker);

        $crash = $this->app->make(JobWarden::class)->dispatch(CrashJob::class, [], ['idempotent' => false])->id;
        $good = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, ['marker' => $marker], ['idempotent' => true])->id;

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$crash, $good], timeout: 25.0);

        $this->assertSame(JobState::Failed, $final[$crash]->state, 'the crashed fork was contained and recorded Failed');
        $this->assertSame(JobState::Succeeded, $final[$good]->state, 'the sibling job was unaffected by the crash');

        // The master itself is alive and serving — a fork crash did not take it or its
        // connection down (the crux of fork-per-job over a shared persistent pool).
        $this->assertSame(1, (int) DB::connection('jobwarden')->select('SELECT 1 AS x')[0]->x, 'master survived the child crash');

        @unlink($marker);
    }

    /**
     * REGRESSION (field report, prefork cutover): 15% of failed jobs lost their error
     * on the first full prefork day. The host application's default log channel — a
     * php://stderr handler that does not swallow its own exceptions — was inherited
     * across the fork into a child whose fd 1/2 had been released, so the first log
     * call threw, unwound past the child's error report, and left the attempt with
     * error=NULL. The supervisor then synthesized `ProcessDied: child exited with code
     * 0 after Ns without reporting` over a perfectly diagnosable exception.
     *
     * This drives the real supervisor under exactly that hostile channel: the child
     * must still report its own failure, with the real exception on the row.
     */
    public function test_a_forked_failure_reports_the_real_exception_under_a_hostile_host_log_channel(): void
    {
        config([
            'logging.default' => 'host_app',
            'logging.channels.host_app' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => 'php://stderr', 'level' => \Monolog\Level::Error],
            ],
        ]);

        $job = $this->app->make(JobWarden::class)->dispatch(
            FailingJob::class,
            ['message' => 'AWD eligibility check failed'],
            ['idempotent' => false, 'max_attempts' => 1],
        );

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);

        $this->assertSame(JobState::Failed, $final[$job->id]->state);

        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $this->assertSame(AttemptState::Failed, $attempt->state);

        $error = $attempt->error;
        $this->assertNotNull($error, 'the child never got its error onto the row — the supervisor will bury it under a synthesized ProcessDied');
        $this->assertSame(\RuntimeException::class, $error['class'], 'the corpse was described instead of the exception');
        $this->assertStringContainsString('AWD eligibility check failed', $error['message']);
        $this->assertStringContainsString('FailingJob', $error['file'], 'the throw site is recorded');

        $this->assertStringContainsString('AWD eligibility check failed', $final[$job->id]->last_error['message']);

        // ...and the child's own step=failed line is in the log, not just the
        // supervisor's step=reaped epitaph.
        $steps = DB::connection('jobwarden')->table('jobwarden_job_logs')
            ->where('attempt_id', $attempt->id)->pluck('step')->all();
        $this->assertContains('failed', $steps, 'the child died before it could log the failure');
    }

    /**
     * Drive the supervisor (tick = reap + admit + claim/fork) until every job is
     * terminal and no child is in flight, then return the jobs keyed by id.
     *
     * @param  string[]  $ids
     * @return array<string, Job>
     */
    private function driveUntil(Supervisor $supervisor, array $ids, float $timeout = 15.0): array
    {
        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Orphaned->value, JobState::Stopped->value];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $supervisor->tick();

            $done = Job::whereIn('id', $ids)->whereIn('state', $terminal)->count();
            if ($done === count($ids) && $supervisor->load() === 0) {
                break;
            }

            usleep(50_000);
        }

        return Job::whereIn('id', $ids)->get()->keyBy('id')->all();
    }
}
