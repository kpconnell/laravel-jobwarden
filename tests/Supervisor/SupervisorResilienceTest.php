<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Logging\JobLogger;
use JobWarden\Models\Worker;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;

/**
 * The run loop must OUTLAST transient infrastructure failure (a dead supervisor
 * gets its healthy children killed by Tier-2, so a DB blip must not become
 * killed work) — but must still DIE LOUDLY on a deterministic failure, because a
 * supervisor that heartbeats while every tick fails looks healthy to all three
 * recovery tiers while doing nothing.
 */
final class SupervisorResilienceTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Fast loop + fast failure backoff so the tests run in milliseconds.
            'jobwarden.supervisor.poll_min_ms' => 1,
            'jobwarden.supervisor.poll_interval_ms' => 1,
            'jobwarden.supervisor.poll_idle_ms' => 1,
            'jobwarden.supervisor.tick_failure_backoff_ms' => 1,
        ]);

        $this->setUpJobWardenSchema();
    }

    private function supervisor(): Supervisor
    {
        return new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(\JobWarden\Stamp\ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
            $this->app->make(WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(JobLogger::class),
            capacity: 1,
        );
    }

    public function test_a_transient_failure_is_absorbed_and_the_loop_keeps_running(): void
    {
        $supervisor = $this->supervisor();
        $worker = $supervisor->boot();

        $ticks = 0;
        $supervisor->run(function () use (&$ticks, $supervisor): void {
            $ticks++;
            if ($ticks === 1) {
                throw new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');
            }
            $supervisor->drain(); // second healthy tick: stop the loop
        });

        // run() returned instead of rethrowing — the blip was outlasted — and the
        // clean-drain shutdown still marked the worker row stopped.
        $this->assertGreaterThanOrEqual(2, $ticks);
        $this->assertSame('stopped', Worker::find($worker->id)->state);
    }

    public function test_transient_failures_never_trip_the_deterministic_limit(): void
    {
        $supervisor = $this->supervisor();

        $ticks = 0;
        $supervisor->run(function () use (&$ticks, $supervisor): void {
            $ticks++;
            if ($ticks <= 6) { // more consecutive failures than TICK_FAILURE_LIMIT
                throw new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');
            }
            $supervisor->drain();
        });

        $this->assertSame(7, $ticks);
    }

    public function test_a_deterministic_failure_exits_loudly_after_the_limit(): void
    {
        $supervisor = $this->supervisor();

        $ticks = 0;
        try {
            $supervisor->run(function () use (&$ticks): void {
                $ticks++;
                throw new \RuntimeException('same bug every tick');
            });
            $this->fail('run() should rethrow a deterministic failure at the limit');
        } catch (\RuntimeException $e) {
            $this->assertSame('same bug every tick', $e->getMessage());
        }

        // Absorbed exactly LIMIT-1 times, rethrown on the LIMIT-th strike.
        $this->assertSame(5, $ticks);
    }

    public function test_a_transient_blip_resets_the_deterministic_streak(): void
    {
        $supervisor = $this->supervisor();

        // 4 deterministic strikes (one short of the limit), a transient one that
        // resets the streak, then 4 more — the loop must survive all of it.
        $ticks = 0;
        $supervisor->run(function () use (&$ticks, $supervisor): void {
            $ticks++;
            if ($ticks <= 4 || ($ticks >= 6 && $ticks <= 9)) {
                throw new \RuntimeException('deterministic');
            }
            if ($ticks === 5) {
                throw new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');
            }
            $supervisor->drain();
        });

        $this->assertSame(10, $ticks);
    }
}
