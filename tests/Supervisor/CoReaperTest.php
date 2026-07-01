<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Supervisor\CoReaper;
use JobWarden\Tests\TestCase;

/**
 * The process-management mechanics of the bundled reaper, exercised with trivial
 * child commands (sleep) so it's independent of the reaper itself: it spawns a
 * child, respawns one that exits, and stops it cleanly.
 */
final class CoReaperTest extends TestCase
{
    public function test_it_spawns_and_stops_a_child(): void
    {
        $sidecar = new CoReaper(['sleep', '30'], sys_get_temp_dir());
        $sidecar->start();
        $this->assertTrue($sidecar->running(), 'child spawned');

        $sidecar->stop(2);
        $this->assertFalse($sidecar->running(), 'child stopped on drain');
    }

    public function test_ensure_alive_respawns_a_child_that_exited(): void
    {
        $sidecar = new CoReaper(['sh', '-c', 'sleep 0.2'], sys_get_temp_dir());
        $sidecar->start();

        usleep(500_000); // let the short child exit on its own
        $this->assertFalse($sidecar->running(), 'the child has exited');

        $sidecar->ensureAlive();
        $this->assertTrue($sidecar->running(), 'ensureAlive respawned it in place');

        $sidecar->stop(2);
        $this->assertFalse($sidecar->running());
    }

    public function test_stop_prevents_further_respawns(): void
    {
        $sidecar = new CoReaper(['sleep', '30'], sys_get_temp_dir());
        $sidecar->start();
        $sidecar->stop(2);

        // After a graceful stop, ensureAlive() is a no-op — the host is going down.
        $sidecar->ensureAlive();
        $this->assertFalse($sidecar->running());
    }
}
