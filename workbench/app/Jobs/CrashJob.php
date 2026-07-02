<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * SIGKILLs itself mid-handle to simulate a hard crash / OOM — the child dies
 * WITHOUT reporting, so the supervisor's Tier-1 waitpid must record the signal
 * and force the attempt to a terminal state.
 */
final class CrashJob implements JobWardenJob
{
    public function __construct(private readonly string $marker = '')
    {
    }

    public function handle(JobContext $context): void
    {
        if ($this->marker !== '') {
            file_put_contents($this->marker, 'reached');
        }

        // Raw output that bypasses the Log facade — the kind of "dying words"
        // the supervisor must drain into job_logs on reap. Via php://stderr, NOT
        // the STDERR constant, which prefork children close (docs/JOB-AUTHORING.md).
        file_put_contents('php://stderr', "JOBWARDEN-DYING-WORDS: about to be SIGKILLed\n");

        posix_kill((int) getmypid(), 9); // SIGKILL self
        sleep(30); // never reached
    }

    public function idempotent(): bool
    {
        return false;
    }
}
