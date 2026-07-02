<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * Resists SIGTERM (re-sleeps through signal interruptions), modelling a wedged /
 * CPU-bound job that can't gracefully stop — so only SIGKILL ends it. Used to
 * exercise the local reaper's SIGTERM→SIGKILL escalation against a reparented
 * child of a dead supervisor. Deliberately never checks stopRequested().
 */
final class StubbornJob implements JobWardenJob
{
    public function __construct(
        private readonly int $duration = 20,
        private readonly string $marker = '',
    ) {
    }

    public function handle(JobContext $context): void
    {
        $end = time() + $this->duration;

        while (time() < $end) {
            @time_nanosleep(1, 0); // a signal interrupts the sleep; the loop just continues
        }

        if ($this->marker !== '') {
            file_put_contents($this->marker, 'finished');
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
