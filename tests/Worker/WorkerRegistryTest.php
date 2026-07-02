<?php

declare(strict_types=1);

namespace JobWarden\Tests\Worker;

use JobWarden\Models\Worker;
use JobWarden\Worker\WorkerRegistry;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class WorkerRegistryTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_register_persists_a_worker_row(): void
    {
        $registry = $this->app->make(WorkerRegistry::class);
        $worker = $registry->register('supervisor', 3);

        $this->assertTrue(Worker::query()->whereKey($worker->id)->exists());
        $this->assertSame('active', Worker::find($worker->id)->state);
    }

    public function test_heartbeat_self_heals_a_vanished_worker_row(): void
    {
        $registry = $this->app->make(WorkerRegistry::class);
        $worker = $registry->register('supervisor', 3);
        $id = $worker->id;

        // Simulate a dev `migrate:fresh` / prune wiping the row out from under us.
        Worker::query()->whereKey($id)->delete();
        $this->assertFalse(Worker::query()->whereKey($id)->exists());

        // A heartbeat must re-create it under the SAME id (so worker_id FKs hold).
        $registry->heartbeat($worker, 1);

        $this->assertTrue(Worker::query()->whereKey($id)->exists());
        $this->assertSame(1, Worker::find($id)->current_load);
    }

    public function test_timestamps_are_stamped_from_the_db_clock_not_the_app_timezone(): void
    {
        // Regression (first production incident): the app ran app.timezone=America/New_York
        // while the DB clock was UTC. heartbeat_at was written with Carbon::now() (app
        // clock) but the reapers compare it against the DB clock — so a just-written beat
        // looked ~4h stale and the global reaper orphaned every live worker on its first
        // scan. Every coordination timestamp must be stamped from the DB clock, independent
        // of app.timezone.
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $registry = $this->app->make(WorkerRegistry::class);
        $worker = $registry->register('supervisor', 3);

        $this->assertLessThan(60, $this->skewFromDbClockSeconds($worker->id),
            'register() stamped heartbeat_at off the DB clock (on the app timezone)');

        $registry->heartbeat($worker, 1);
        $this->assertLessThan(60, $this->skewFromDbClockSeconds($worker->id),
            'heartbeat() stamped heartbeat_at off the DB clock (on the app timezone)');
    }

    /** Absolute seconds between the row's heartbeat_at and the DB's own CURRENT_TIMESTAMP. */
    private function skewFromDbClockSeconds(string $id): int
    {
        $conn = (new Worker)->getConnection();
        $table = (new Worker)->getTable();
        $row = $conn->selectOne(
            "SELECT heartbeat_at AS hb, CURRENT_TIMESTAMP AS db_now FROM {$table} WHERE id = ?",
            [$id]
        );

        // Both values come from the DB; parsing both with the same interpretation preserves
        // the true offset — an app-clock write surfaces as the UTC↔local-timezone gap.
        return (int) abs(strtotime((string) $row->db_now) - strtotime((string) $row->hb));
    }
}
