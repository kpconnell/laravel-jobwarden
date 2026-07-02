<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Logging\JobLogger;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Fake\FakeProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Supervisor\ChildHandle;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Daemons emit structured lifecycle logs through the standard Log facade (which,
 * in production, the operator routes to stdout for container/cloud capture).
 */
final class SupervisorLoggingTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_supervisor_logs_its_lifecycle_via_the_standard_facade(): void
    {
        /** @var array<int,array{level:string,message:string}> $records */
        $records = [];
        Log::listen(function ($event) use (&$records): void {
            $records[] = ['level' => $event->level, 'message' => $event->message];
        });

        $supervisor = new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
            $this->app->make(WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(JobLogger::class),
            2,
        );

        $supervisor->boot();
        $supervisor->drain();
        $supervisor->tick();   // announces draining
        // shutdown path:
        $reflection = new \ReflectionMethod($supervisor, 'shutdown');
        $reflection->invoke($supervisor);

        $messages = array_column($records, 'message');
        $this->assertContains('jobwarden supervisor started', $messages);
        $this->assertContains('supervisor draining (stop received); no longer claiming', $messages);
        $this->assertContains('jobwarden supervisor stopped', $messages);

        // The "started" record carries structured context an aggregator can use.
        $started = array_values(array_filter($records, fn ($r) => $r['message'] === 'jobwarden supervisor started'));
        $this->assertSame('info', $started[0]['level']);
    }

    public function test_stop_lifecycle_is_injected_into_the_job_log(): void
    {
        config(['jobwarden.supervisor.graceful_timeout' => 10]);

        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running, 'attempt_count' => 1]);
        Job::where('id', $job->id)->update([
            'cancel_requested' => true, 'cancel_mode' => 'cancel', 'cancel_reason' => 'no longer needed',
        ]);
        $attempt = JobAttempt::create([
            'job_id' => $job->id, 'attempt_number' => 1,
            'state' => AttemptState::Running, 'fencing_token' => 1,
        ]);

        $supervisor = new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            new FakeProbe,   // never signal a real pid, regardless of OS
            $this->app->make(WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(JobLogger::class),
            2,
        );
        $handle = new ChildHandle(
            process: null,
            pid: 4242,
            attemptId: (string) $attempt->id,
            jobId: (string) $job->id,
            fencingToken: 1,
            startedAt: microtime(true),
        );
        $escalate = new \ReflectionMethod($supervisor, 'escalateStopIfRequested');

        // Cancel noticed → SIGTERM plus an immediate "cancel requested" line.
        $escalate->invoke($supervisor, $handle);

        // Grace expired → SIGKILL, logged once even though escalation re-fires each tick.
        $handle->stopRequestedAt = microtime(true) - 11;
        $escalate->invoke($supervisor, $handle);
        $escalate->invoke($supervisor, $handle);

        // Child dies → finalize records the terminal stop.
        $handle->termSignal = 9;
        (new \ReflectionMethod($supervisor, 'finalize'))->invoke($supervisor, $handle);

        $lines = JobLog::where('attempt_id', $attempt->id)->orderBy('seq')->get();
        $this->assertSame([
            'cancel requested: no longer needed; SIGTERM sent (10s grace, then SIGKILL)',
            'grace window (10s) expired; SIGKILL sent',
            'stopped by operator; child reaped (exit=n/a signal=9)',
        ], $lines->pluck('body_ref')->all());
        $this->assertSame(['stop'], $lines->pluck('step')->unique()->all());
        $this->assertSame(['warning'], $lines->pluck('level')->unique()->all());

        $this->assertSame(AttemptState::Stopped, JobAttempt::find($attempt->id)->state);
        $this->assertSame(JobState::Stopped, Job::find($job->id)->state);
    }
}
