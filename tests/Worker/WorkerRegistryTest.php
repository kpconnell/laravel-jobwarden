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
}
