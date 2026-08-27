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
use Illuminate\Support\Facades\Event;
use Workbench\App\Jobs\ChattyJob;
use Workbench\App\Jobs\CrashJob;
use Workbench\App\Jobs\DestructorProbeJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\ForkReportJob;
use Workbench\App\Jobs\MarkerJob;
use Illuminate\Support\Facades\Log;
use Workbench\App\Support\ForkProbe;
use Workbench\App\Support\SocketProbe;
use Workbench\App\Support\SocketProbeFacade;

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
     * Recycling exists to rebaseline the master's memory; it must never be worth stalling
     * a box for. Crossing the fork threshold used to request a drain immediately, and a
     * drain claims nothing until the last in-flight fork finishes — so on a mixed host one
     * hour-long job idled every other slot for that whole hour.
     *
     * Past the threshold with work in flight, the master must keep claiming.
     */
    public function test_crossing_the_recycle_threshold_does_not_stop_claiming_while_work_is_in_flight(): void
    {
        config([
            'jobwarden.supervisor.prefork_recycle_after' => 1,
            'jobwarden.supervisor.prefork_recycle_grace' => 3600,
        ]);

        $slowMarker = $this->storage.'/recycle-slow.txt';
        $fastMarker = $this->storage.'/recycle-fast.txt';
        @unlink($slowMarker);
        @unlink($fastMarker);

        $warden = $this->app->make(JobWarden::class);
        $slow = $warden->dispatch(MarkerJob::class, ['sleep' => 4, 'marker' => $slowMarker])->id;

        $supervisor = $this->supervisor(capacity: 4);
        $supervisor->boot();

        // One tick forks the slow job: the master is now PAST its recycle threshold
        // (1 fork) with a child holding a slot for the next 4 seconds.
        $supervisor->tick();
        $this->assertSame(1, $supervisor->load(), 'the slow job should be in flight');

        // A job dispatched now must still be picked up — that is the whole fix.
        $fast = $warden->dispatch(MarkerJob::class, ['marker' => $fastMarker])->id;
        $final = $this->driveUntil($supervisor, [$slow, $fast], timeout: 25.0);

        $this->assertSame(JobState::Succeeded, $final[$fast]->state,
            'the master stalled: it stopped claiming at the threshold instead of waiting for an idle moment');
        $this->assertSame(JobState::Succeeded, $final[$slow]->state);

        @unlink($slowMarker);
        @unlink($fastMarker);
    }

    /**
     * The other half of the same decision: once nothing is in flight the drain is free,
     * so the master takes it immediately and stops claiming (run() then returns and the
     * launcher restarts it on a pristine baseline).
     */
    public function test_the_master_recycles_at_the_first_idle_moment_past_the_threshold(): void
    {
        config([
            'jobwarden.supervisor.prefork_recycle_after' => 1,
            'jobwarden.supervisor.prefork_recycle_grace' => 3600,
        ]);

        $marker = $this->storage.'/recycle-idle.txt';
        @unlink($marker);

        $warden = $this->app->make(JobWarden::class);
        $first = $warden->dispatch(MarkerJob::class, ['marker' => $marker])->id;

        $supervisor = $this->supervisor(capacity: 4);
        $supervisor->boot();
        $this->driveUntil($supervisor, [$first]);

        // Threshold crossed and nothing in flight — the next tick recycles, so this job
        // is left for the supervisor that replaces us.
        $after = $warden->dispatch(MarkerJob::class, [])->id;
        for ($i = 0; $i < 5; $i++) {
            $supervisor->tick();
            usleep(50_000);
        }

        $this->assertSame(JobState::Queued, Job::find($after)->state,
            'the master kept claiming after recycling; it should have drained at the idle moment');

        @unlink($marker);
    }

    /**
     * Deferring is not the same as never recycling: a host that stays busy indefinitely
     * would otherwise grow past its baseline forever. Once the grace window expires the
     * master drains anyway — the stall we normally avoid, accepted as the rare fallback.
     */
    public function test_the_recycle_grace_expiring_drains_even_with_work_in_flight(): void
    {
        config([
            'jobwarden.supervisor.prefork_recycle_after' => 1,
            'jobwarden.supervisor.prefork_recycle_grace' => 1,
        ]);

        $slowMarker = $this->storage.'/recycle-grace.txt';
        @unlink($slowMarker);

        $warden = $this->app->make(JobWarden::class);
        $warden->dispatch(MarkerJob::class, ['sleep' => 4, 'marker' => $slowMarker]);

        $supervisor = $this->supervisor(capacity: 4);
        $supervisor->boot();
        $supervisor->tick();
        $this->assertSame(1, $supervisor->load());

        // The grace clock starts when the master first NOTICES it is past the threshold,
        // which is the tick after the one that forked — so tick again to start it.
        $supervisor->tick();

        // Sit past the 1s grace with the slow job still running, then tick: the master
        // gives up waiting for an idle moment and drains.
        sleep(2);
        $blocked = $warden->dispatch(MarkerJob::class, [])->id;
        $supervisor->tick();

        $this->assertSame(JobState::Queued, Job::find($blocked)->state,
            'the grace window expired but the master kept claiming');

        @unlink($slowMarker);
    }

    // ------------------------------------------------------------------
    // Fork safety (docs/HOSTING.md "Fork safety"): the three-tier contract,
    // each tier pinned by a direct behavioural assertion in a REAL fork.
    // ------------------------------------------------------------------

    /**
     * Tier 1, directly: the storm test proves the master's connection SURVIVES; this
     * proves the child actually ran on a DIFFERENT DB session. Silent interleaving on
     * the inherited socket "works" at low concurrency and corrupts in prod — exactly
     * the failure mode a survival-only assertion can't see.
     */
    public function test_a_forked_child_talks_on_its_own_db_session_not_the_masters(): void
    {
        $report = $this->storage.'/fork-report-'.bin2hex(random_bytes(4)).'.json';
        @unlink($report);

        $masterBackendId = $this->backendId();
        $job = $this->app->make(JobWarden::class)->dispatch(ForkReportJob::class, ['report' => $report]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $facts = json_decode((string) file_get_contents($report), true);
        $this->assertNotSame(getmypid(), $facts['child_pid'], 'the report was written by the master, not a fork');
        $this->assertNotSame($masterBackendId, $facts['db_backend_id'],
            'the child queried on the MASTER\'S DB session — the inherited socket was reused instead of reconnected');

        // ...and the master is still on the same session it started on: nothing the
        // child did reconnected or replaced the master's own handle.
        $this->assertSame($masterBackendId, $this->backendId(), 'the master\'s session changed underneath it');

        @unlink($report);
    }

    /**
     * Tier 1: the child must terminate via pcntl_exec — no PHP shutdown functions, no
     * destructors — because a destructor is precisely the code path that would send a
     * graceful goodbye (COM_QUIT, TLS close_notify) down a socket shared with the
     * master. The job plants both tripwires; neither may fire.
     */
    public function test_a_forked_child_never_runs_shutdown_functions_or_destructors(): void
    {
        $shutdown = $this->storage.'/tripwire-shutdown.txt';
        $destructor = $this->storage.'/tripwire-destructor.txt';
        @unlink($shutdown);
        @unlink($destructor);

        $job = $this->app->make(JobWarden::class)->dispatch(DestructorProbeJob::class, [
            'shutdownMarker' => $shutdown,
            'destructorMarker' => $destructor,
        ]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state, 'the tripwires were never even planted');

        $this->assertFileDoesNotExist($shutdown,
            'a register_shutdown_function ran in the child — it no longer exits via pcntl_exec, so PDO destructors can now COM_QUIT the master\'s socket');
        $this->assertFileDoesNotExist($destructor,
            'an object destructor ran in the child — it no longer exits via pcntl_exec, so PDO destructors can now COM_QUIT the master\'s socket');
    }

    /**
     * Tier 1: siblings forked from the same master image must not mint identical
     * mt_rand values (fork copies the userland MT state; resetAfterFork reseeds) and
     * each must hold its own DB session. Honest scope, per adversarial review: mt_rand
     * is the ONLY entropy axis that can catch a deleted reseed, and even it passes
     * accidentally if the master advances MT state between forks. random_bytes/uuid
     * draw from the kernel CSPRNG per call and physically cannot collide across forks
     * — they are asserted as sanity, not as coverage of any reset. child_pid
     * uniqueness is likewise trivial while forks coexist.
     */
    public function test_sibling_forks_share_no_entropy_and_no_db_session(): void
    {
        $n = 8;
        $ids = [];
        $reports = [];
        for ($i = 0; $i < $n; $i++) {
            $reports[$i] = $this->storage.'/entropy-'.$i.'.json';
            @unlink($reports[$i]);
            $ids[] = $this->app->make(JobWarden::class)
                ->dispatch(ForkReportJob::class, ['report' => $reports[$i]])->id;
        }

        $supervisor = $this->supervisor(capacity: 4);
        $supervisor->boot();
        $this->driveUntil($supervisor, $ids, timeout: 40.0);

        $facts = array_map(fn (string $r) => json_decode((string) file_get_contents($r), true), $reports);
        foreach (['child_pid', 'db_backend_id', 'mt_rand', 'random_bytes', 'uuid'] as $key) {
            $values = array_column($facts, $key);
            $this->assertCount($n, array_unique($values), "siblings shared a {$key} — forks are not independent on this axis");
        }

        foreach ($reports as $r) {
            @unlink($r);
        }
    }

    /**
     * Tier 2: a container service on the prefork_forget list, resolved by the master
     * before the fork, must be REBUILT inside the child on first use (fresh instance,
     * fresh sockets) — while the master keeps its own instance untouched.
     */
    public function test_the_prefork_forget_list_rebuilds_a_listed_service_inside_the_fork(): void
    {
        $this->app->singleton('workbench.fork-probe', ForkProbe::class);
        $masterProbe = $this->app->make('workbench.fork-probe');
        $this->assertSame(getmypid(), $masterProbe->constructedInPid);

        config(['jobwarden.supervisor.prefork_forget' => array_merge(
            config('jobwarden.supervisor.prefork_forget'),
            ['workbench.fork-probe'],
        )]);

        $report = $this->storage.'/forget-report.json';
        @unlink($report);
        $job = $this->app->make(JobWarden::class)->dispatch(ForkReportJob::class, ['report' => $report]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $facts = json_decode((string) file_get_contents($report), true);
        $this->assertNotNull($facts['probe_constructed_pid'], 'the child never saw the probe binding');
        $this->assertSame($facts['child_pid'], $facts['probe_constructed_pid'],
            'the child resolved the MASTER\'S inherited instance — prefork_forget did not drop the binding, so a real service here would be sharing the master\'s sockets');

        $this->assertSame($masterProbe, $this->app->make('workbench.fork-probe'),
            'the master\'s own instance was replaced — the forget must happen only inside the child');

        @unlink($report);
    }

    /**
     * Tier 2 at the KERNEL level, through the access path real apps use. Object
     * identity is only a proxy — the property that matters is "different socket". A
     * TCP connection's local ephemeral ip:port is its kernel identity (a fresh
     * connection cannot reuse it while the original is open), so this binds a
     * singleton that opens a real TCP connection, resolves it in the master THROUGH A
     * FACADE (populating the static Facade cache the fork inherits copy-on-write),
     * lists it on prefork_forget BY A CONTAINER ALIAS, and asserts the child — also
     * going through the facade — reports a different local port than the master.
     *
     * This one test regresses both silent-defeat bugs the adversarial review
     * confirmed: an alias key that forgetInstance() would miss without normalization,
     * and the facade static cache that would bypass the container entirely.
     */
    public function test_the_forget_list_severs_a_facade_accessed_aliased_socket_service_at_the_kernel_level(): void
    {
        // Closure concrete, NOT the class name: with the alias below, a class-name
        // concrete would alias-resolve back to the abstract and recurse forever.
        $this->app->singleton('workbench.socket-probe', fn () => new SocketProbe);
        $this->app->alias('workbench.socket-probe', SocketProbe::class);
        config(['jobwarden.supervisor.prefork_forget' => array_merge(
            config('jobwarden.supervisor.prefork_forget'),
            [SocketProbe::class],   // the ALIAS, not the base key — must still work
        )]);

        // Resolve through the facade so Facade::$resolvedInstance is populated in the
        // master before the fork — the exact state that used to defeat the forget.
        $masterSocket = SocketProbeFacade::localName();
        $this->assertSame(getmypid(), SocketProbeFacade::getFacadeRoot()->constructedInPid);

        $report = $this->storage.'/socket-report.json';
        @unlink($report);
        $job = $this->app->make(JobWarden::class)->dispatch(ForkReportJob::class, ['report' => $report]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $facts = json_decode((string) file_get_contents($report), true);
        $this->assertNotNull($facts['socket_local'], 'the child never saw the probe binding');
        $this->assertSame($facts['child_pid'], $facts['socket_constructed_pid'],
            'the facade handed the child the MASTER\'S inherited instance — the static facade cache or the alias defeated prefork_forget');
        $this->assertNotSame($masterSocket, $facts['socket_local'],
            'same local ip:port = the child is on the MASTER\'S kernel socket, not a fresh connection');

        // The master's side is untouched: same instance, same socket, still open.
        $this->assertSame($masterSocket, SocketProbeFacade::localName(),
            'the master\'s probe connection changed — the forget leaked out of the child');

        @unlink($report);
    }

    /**
     * Tier 2 for a SHIPPED default ('log'), through the facade. Shared context lives
     * on the LogManager instance: the master stamps it via Log::, and a child whose
     * Log:: still serves the inherited manager would see the stamp. A rebuilt manager
     * comes back empty. Without this, no test exercises any of the default
     * prefork_forget keys — a typo'd or renamed default would no-op with zero signal.
     */
    public function test_the_shipped_log_default_rebuilds_fresh_through_the_facade_inside_the_fork(): void
    {
        Log::shareContext(['jobwarden_master_boot_pid' => getmypid()]);
        $this->assertArrayHasKey('jobwarden_master_boot_pid', Log::sharedContext());

        $report = $this->storage.'/log-default-report.json';
        @unlink($report);
        $job = $this->app->make(JobWarden::class)->dispatch(ForkReportJob::class, ['report' => $report]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $facts = json_decode((string) file_get_contents($report), true);
        $this->assertArrayNotHasKey('jobwarden_master_boot_pid', (array) $facts['log_shared_context'],
            'Log:: in the child served the MASTER\'S inherited LogManager — the shipped \'log\' default (or its facade clearing) no longer works');

        // The master's manager keeps its stamp — the reset happened only in the child.
        $this->assertArrayHasKey('jobwarden_master_boot_pid', Log::sharedContext());
        Log::flushSharedContext();

        @unlink($report);
    }

    /**
     * Tier 3: the app's after-fork hook. A listener registered once in the master
     * (inherited copy-on-write) must fire INSIDE the child, with the right attempt
     * and the child's pid — and the documented ORDERING must hold at fire time: the
     * whole point of the hook is that a listener may safely open connections because
     * Tiers 1–2 already ran. So the listener itself records the evidence: the DB
     * session it sees (must already be the child's own, not the master's) and the
     * construction pid of a forget-listed service (must already be rebuilt). Moving
     * the event() above the resets in runChild fails this test.
     */
    public function test_the_prefork_child_starting_event_fires_inside_the_child(): void
    {
        $this->app->singleton('workbench.fork-probe', ForkProbe::class);
        $this->app->make('workbench.fork-probe');
        config(['jobwarden.supervisor.prefork_forget' => array_merge(
            config('jobwarden.supervisor.prefork_forget'),
            ['workbench.fork-probe'],
        )]);
        $masterBackendId = $this->backendId();

        $hookMarker = $this->storage.'/hook-'.bin2hex(random_bytes(4)).'.json';
        @unlink($hookMarker);

        $backendId = fn (): int => $this->backendId();
        Event::listen(\JobWarden\Events\PreforkChildStarting::class,
            static function (\JobWarden\Events\PreforkChildStarting $e) use ($hookMarker, $backendId): void {
                file_put_contents($hookMarker, json_encode([
                    'attempt_id' => $e->attemptId,
                    'event_pid' => $e->childPid,
                    'running_pid' => getmypid(),
                    'db_backend_id_at_fire' => $backendId(),
                    'probe_pid_at_fire' => app('workbench.fork-probe')->constructedInPid,
                ]));
            });

        $job = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, []);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $this->assertFileExists($hookMarker, 'PreforkChildStarting never fired — apps have no after-fork hook');
        $hook = json_decode((string) file_get_contents($hookMarker), true);

        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $this->assertSame($attempt->id, $hook['attempt_id']);
        $this->assertSame((int) $attempt->child_pid, $hook['event_pid'], 'the event does not carry the fork\'s own pid');
        $this->assertSame($hook['event_pid'], $hook['running_pid'], 'the listener ran in the wrong process — the hook must fire INSIDE the child');
        $this->assertNotSame(getmypid(), $hook['running_pid'], 'the hook fired in the master, not the fork');

        // The ordering half of the contract, observed AT FIRE TIME by the listener:
        $this->assertNotSame($masterBackendId, $hook['db_backend_id_at_fire'],
            'at fire time the listener was on the MASTER\'S DB session — the event moved above the DB reconnect');
        $this->assertSame($hook['running_pid'], $hook['probe_pid_at_fire'],
            'at fire time a forget-listed service was still the master\'s instance — the event moved above the forget');

        @unlink($hookMarker);
    }

    /**
     * The forget list drops 'log' and every facade root in the child — so the
     * handler's OWN logging must demonstrably still work end-to-end under prefork:
     * standard Log:: calls from the handler are captured into job_logs (through the
     * rebuilt LogManager + JobLogCapture), and a raw php://stdout write lands in the
     * attempt log the supervisor ingests into job_logs on reap. Without this, the only
     * prefork evidence was ChildRunner's own step lines.
     */
    public function test_a_handlers_log_facade_lines_and_raw_stdout_both_reach_job_logs_under_prefork(): void
    {
        $job = $this->app->make(JobWarden::class)->dispatch(ChattyJob::class, [
            'steps' => 2,
            'stdout' => 'RAW-STDOUT-FROM-THE-HANDLER',
        ]);

        $supervisor = $this->supervisor();
        $supervisor->boot();
        $final = $this->driveUntil($supervisor, [$job->id]);
        $this->assertSame(JobState::Succeeded, $final[$job->id]->state);

        $attempt = JobAttempt::where('job_id', $job->id)->first();
        $sink = $this->app->make(\JobWarden\Logging\Contracts\LogBodySink::class);
        $rows = DB::connection('jobwarden')->table('jobwarden_job_logs')
            ->where('attempt_id', $attempt->id)->get(['body_ref', 'step'])
            ->map(fn ($r) => ['step' => $r->step, 'message' => (string) $sink->resolve($r->body_ref)]);
        $messages = $rows->pluck('message')->all();

        $this->assertContains('working step 1/2', $messages, 'the handler\'s Log::info did not reach job_logs — the rebuilt LogManager is not captured');
        $this->assertContains('all steps complete', $messages, 'the handler\'s Log::notice did not reach job_logs');

        $stdout = $rows->firstWhere('step', 'process_output');
        $this->assertNotNull($stdout, 'no process_output row — the child\'s raw stdout never reached the attempt log or was not ingested');
        $this->assertStringContainsString('RAW-STDOUT-FROM-THE-HANDLER', $stdout['message']);
    }

    /** The current backend/session id of the master's own jobwarden connection. */
    private function backendId(): int
    {
        $conn = DB::connection('jobwarden');

        return match ($conn->getDriverName()) {
            'pgsql' => (int) $conn->select('SELECT pg_backend_pid() AS id')[0]->id,
            default => (int) $conn->select('SELECT CONNECTION_ID() AS id')[0]->id,
        };
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
