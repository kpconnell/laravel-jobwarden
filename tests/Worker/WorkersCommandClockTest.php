<?php

declare(strict_types=1);

namespace JobWarden\Tests\Worker;

use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Support\Facades\Artisan;

/**
 * `jobwarden:workers` exists to answer "is this host's lease fresh?" at a glance, so its
 * heartbeat column has to be measured against the clock the heartbeat was written on.
 *
 * heartbeat_at is stamped from the DB clock (WorkerRegistry::stampDbClock). The command
 * used to read it back through Eloquent's datetime cast and subtract Carbon::now(),
 * mixing the two frames: a heartbeat written one second ago printed as `14399s ago`
 * under app.timezone=America/New_York — four hours of apparent staleness on a live
 * worker, which is precisely the signal an operator would act on.
 *
 * No frozen clock: setTestNow collapses the app and DB clocks into one literal.
 */
final class WorkersCommandClockTest extends TestCase
{
    use RefreshesJobWardenSchema;

    private ?string $originalDefaultTz = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultTz = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $this->setUpJobWardenSchema();
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultTz !== null) {
            date_default_timezone_set($this->originalDefaultTz);
        }

        parent::tearDown();
    }

    public function test_a_fresh_heartbeat_reads_as_fresh_under_a_skewed_app_timezone(): void
    {
        $registry = $this->app->make(WorkerRegistry::class);
        $registry->heartbeat($registry->register('supervisor'));

        Artisan::call('jobwarden:workers');

        $this->assertSame(1, preg_match('/\|\s*(\d+)s ago\s*\|/', Artisan::output(), $m), 'no heartbeat age in the table');
        $this->assertLessThanOrEqual(5, (int) $m[1], "a just-written heartbeat printed as {$m[1]}s stale — the age was measured against the app clock");
    }
}
