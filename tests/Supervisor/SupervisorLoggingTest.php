<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Logging\JobLogger;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Stamp\ProcessStampWriter;
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
}
