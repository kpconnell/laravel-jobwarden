<?php

declare(strict_types=1);

namespace JobWarden\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;

/**
 * Classifies a failure caught by a long-running loop (supervisor / reaper tick).
 *
 * Transient = infrastructure weather: a lost or failed-over connection, a
 * deadlock, a lock-wait timeout. A loop should absorb these and outlast the
 * outage — exiting gets healthy children killed (Tier-2 treats a dead
 * supervisor's children as reparented orphans) over a blip that fixes itself,
 * and while the DB is down the loop's heartbeat fails too, so the fleet still
 * sees the truth.
 *
 * Everything else is deterministic — a code or schema bug that will recur every
 * tick. Those must escape after a few strikes: a process that heartbeats while
 * its ticks always fail looks healthy to all three recovery tiers while doing
 * no work. The heartbeat is a lie; dying loudly hands recovery to process
 * supervision and the reapers.
 */
final class TransientFailure
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public static function isTransient(\Throwable $e): bool
    {
        $probe = new self;

        // The framework detectors match on the message of the exception they are
        // given; walk the previous-chain ourselves so a wrapped PDO error (e.g.
        // inside a domain exception) is still recognized.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($probe->causedByLostConnection($cause) || $probe->causedByConcurrencyError($cause)) {
                return true;
            }
        }

        return false;
    }
}
