<?php

declare(strict_types=1);

namespace JobWarden\Tests\Scheduling;

use JobWarden\Claim\OptimisticClaimDriver;
use JobWarden\Claim\WorkerContext;
use JobWarden\JobWarden;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\Runner\JobContext;
use JobWarden\Scheduling\ScheduleEvaluator;
use JobWarden\StateMachine\StateMachine;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Workbench\App\Console\EchoCommand;

final class ScheduledCommandTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:05:00', 'UTC'));
        Artisan::registerCommand(new EchoCommand);
        $this->setUpJobWardenSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -- lane isolation ----------------------------------------------------

    public function test_a_worker_only_claims_its_own_lane(): void
    {
        $default = $this->seedQueued('default');
        $scheduled = $this->seedQueued('scheduled');

        $driver = new OptimisticClaimDriver($this->app->make(StateMachine::class));

        // The business fleet (lane=default) can't see the scheduled job, and vice versa.
        $defaultClaim = $driver->claim($this->worker('default'), 10);
        $this->assertCount(1, $defaultClaim);
        $this->assertSame($default->id, $defaultClaim[0]->jobId);

        $scheduledClaim = $driver->claim($this->worker('scheduled'), 10);
        $this->assertCount(1, $scheduledClaim);
        $this->assertSame($scheduled->id, $scheduledClaim[0]->jobId);
    }

    // -- scheduleCommand emits onto the scheduled lane ---------------------

    public function test_schedule_command_emits_a_run_artisan_command_on_the_scheduled_lane(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand(
            'nightly-prune',
            '*/10 * * * *',
            'cache:prune',
            ['--force' => true],
            ['missed_policy' => 'run_latest', 'last_evaluated_at' => null]
        );
        // Pretend the scheduler last looked 11 minutes ago so one occurrence is due.
        $schedule->update(['last_evaluated_at' => Carbon::now()->subMinutes(11)]);

        $this->app->make(ScheduleEvaluator::class)->evaluate($schedule->id);

        $job = Job::where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertSame(RunArtisanCommand::class, $job->job_class);
        $this->assertSame('scheduled', $job->lane, 'scheduled work goes to the dedicated lane');
        $this->assertSame('cache:prune', $job->params['command']);
        $this->assertSame(['--force' => true], $job->params['arguments']);
        $this->assertSame(JobState::Queued, $job->state);
    }

    // -- the wrapper actually runs the command -----------------------------

    public function test_run_artisan_command_succeeds_on_exit_zero(): void
    {
        $ctx = new JobContext('j', 'a', 1, ['command' => 'demo:exit', 'arguments' => ['--code' => 0]]);

        (new RunArtisanCommand)->handle($ctx); // must not throw

        $this->assertTrue(true);
    }

    public function test_run_artisan_command_throws_on_nonzero_exit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exited with code 3');

        $ctx = new JobContext('j', 'a', 1, ['command' => 'demo:exit', 'arguments' => ['--code' => 3]]);
        (new RunArtisanCommand)->handle($ctx);
    }

    // -- idempotency declaration drives host-loss recovery -----------------

    public function test_a_command_declared_idempotent_flows_to_the_job_with_a_retry_budget(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand(
            'nightly-backup', '*/10 * * * *', 'db:backup', [], ['idempotent' => true]
        );
        $schedule->update(['last_evaluated_at' => Carbon::now()->subMinutes(11)]);
        $this->app->make(ScheduleEvaluator::class)->evaluate($schedule->id);

        $job = Job::where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertTrue((bool) $job->idempotent, 'declared idempotent');
        $this->assertGreaterThan(1, (int) $job->max_attempts, 'idempotent commands get a retry budget');
    }

    public function test_a_command_defaults_to_non_idempotent_single_shot(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('prune', '*/10 * * * *', 'cache:prune');
        $schedule->update(['last_evaluated_at' => Carbon::now()->subMinutes(11)]);
        $this->app->make(ScheduleEvaluator::class)->evaluate($schedule->id);

        $job = Job::where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertFalse((bool) $job->idempotent);
        $this->assertSame(1, (int) $job->max_attempts, 'single-shot');
    }

    public function test_host_loss_retries_an_idempotent_command_and_parks_a_non_idempotent_one(): void
    {
        $recovery = $this->app->make(\JobWarden\Recovery\RecoveryService::class);

        // An idempotent command run, lost mid-flight (host died) → orphaned, in budget.
        $idempotent = $this->orphanedCommand(idempotent: true, maxAttempts: 3);
        $recovery->resolveOrphan($idempotent, \JobWarden\States\ActorType::Reaper, 'host dead');
        $this->assertSame(JobState::Retrying, $idempotent->refresh()->state, 'idempotent command re-runs on another host');

        // A non-idempotent command run, lost → outcome indeterminate → park for an operator.
        $nonIdempotent = $this->orphanedCommand(idempotent: false, maxAttempts: 1);
        $recovery->resolveOrphan($nonIdempotent, \JobWarden\States\ActorType::Reaper, 'host dead');
        $this->assertSame(JobState::Orphaned, $nonIdempotent->refresh()->state, 'non-idempotent command parks');
    }

    private function orphanedCommand(bool $idempotent, int $maxAttempts): Job
    {
        return Job::create([
            'job_class' => RunArtisanCommand::class,
            'lane' => 'scheduled',
            'params' => ['command' => 'db:backup'],
            'idempotent' => $idempotent,
            'max_attempts' => $maxAttempts,
            'attempt_count' => 1,
            'state' => JobState::Orphaned,
        ]);
    }

    // -- helpers -----------------------------------------------------------

    private function seedQueued(string $lane): Job
    {
        return Job::create([
            'job_class' => 'X',
            'lane' => $lane,
            'state' => JobState::Queued,
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'attempt_count' => 0,
        ]);
    }

    private function worker(string $lane): WorkerContext
    {
        $w = Worker::create([
            'role' => 'supervisor', 'host_id' => 'h', 'hostname' => 'box', 'pid' => 1,
            'incarnation' => 1, 'state' => 'active', 'capacity' => 5,
            'started_at' => now(), 'heartbeat_at' => now(),
        ]);

        return new WorkerContext($w->id, 'h', 'box', 1, 1, $lane);
    }
}
