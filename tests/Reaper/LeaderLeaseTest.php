<?php

declare(strict_types=1);

namespace JobWarden\Tests\Reaper;

use JobWarden\Reaper\LeaderLease;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class LeaderLeaseTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_only_one_acquirer_wins_and_the_holder_can_refresh(): void
    {
        $lease = $this->app->make(LeaderLease::class);

        $this->assertTrue($lease->acquire('global_reaper', 'reaper-a', 30));
        $this->assertFalse($lease->acquire('global_reaper', 'reaper-b', 30), 'a second reaper cannot steal a live lease');

        // The holder can refresh its own lease.
        $this->assertTrue($lease->acquire('global_reaper', 'reaper-a', 30));
    }

    public function test_an_expired_lease_is_acquirable_by_another(): void
    {
        $lease = $this->app->make(LeaderLease::class);

        // Acquire then immediately let it expire (negative TTL).
        $this->assertTrue($lease->acquire('global_reaper', 'reaper-a', -5));

        // reaper-b can now take over.
        $this->assertTrue($lease->acquire('global_reaper', 'reaper-b', 30));
        // reaper-a is no longer the leader.
        $this->assertFalse($lease->acquire('global_reaper', 'reaper-a', 30));
    }

    public function test_release_frees_the_lease(): void
    {
        $lease = $this->app->make(LeaderLease::class);

        $this->assertTrue($lease->acquire('global_reaper', 'reaper-a', 30));
        $lease->release('global_reaper', 'reaper-a');

        $this->assertTrue($lease->acquire('global_reaper', 'reaper-b', 30));
    }
}
