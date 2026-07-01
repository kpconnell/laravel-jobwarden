<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Models\JobAttempt;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\ProcessStamp;

/**
 * Verifies an attempt's process stamp on THIS host (spec §5.4 Tier 2). Liveness
 * is reuse-proof: a pid counts as alive only if its /proc start-time still
 * matches the stamp, so a recycled pid is never mistaken for the original.
 */
final class StampVerifier
{
    public function __construct(private readonly ProcessProbe $probe)
    {
    }

    public function verdict(JobAttempt $attempt): StampVerdict
    {
        $stamp = ProcessStamp::fromAttempt($attempt);

        $supervisorAlive = $stamp->supervisorPid !== null
            && $this->probe->matches($stamp->supervisorPid, $stamp->supervisorStartTime);

        $childAlive = $stamp->hasChild()
            && $this->probe->matches((int) $stamp->childPid, $stamp->childStartTime);

        return new StampVerdict($stamp, $supervisorAlive, $childAlive);
    }
}
