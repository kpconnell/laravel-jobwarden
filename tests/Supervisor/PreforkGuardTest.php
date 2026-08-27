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
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;
use ReflectionMethod;

/**
 * Tier 1 of the fork-safety contract has one precondition JobWarden cannot fix in the
 * child: a PDO-persistent jobwarden connection. PHP keeps persistent handles in a
 * process-wide list the fork inherits, so the child's "fresh" reconnect gets the
 * MASTER'S socket back and runs on its session. The supervisor must refuse to fork
 * on such a connection and fall back to child mode. No fork happens here — the
 * predicate is pure config — so this runs on every engine.
 */
final class PreforkGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('prefork requires the pcntl extension.');
        }
        config(['jobwarden.supervisor.execution_mode' => 'prefork']);
    }

    private function isPrefork(): bool
    {
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
            1,
        );

        return (bool) (new ReflectionMethod(Supervisor::class, 'isPrefork'))->invoke($supervisor);
    }

    public function test_prefork_is_used_on_a_non_persistent_connection(): void
    {
        $this->assertTrue($this->isPrefork());
    }

    public function test_prefork_is_refused_on_a_pdo_persistent_jobwarden_connection(): void
    {
        $key = 'database.connections.'.config('jobwarden.connection').'.options';
        config([$key => array_replace((array) config($key, []), [\PDO::ATTR_PERSISTENT => true])]);

        $this->assertFalse($this->isPrefork(),
            'the supervisor forked on a PDO-persistent connection — the child would inherit the master\'s persistent handle and run on its DB session');
    }
}
