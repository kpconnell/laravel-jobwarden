<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * Resists SIGTERM (re-sleeps through signal interruptions), modelling a wedged /
 * CPU-bound job that can't gracefully stop — so only SIGKILL ends it. Used to
 * exercise the local reaper's SIGTERM→SIGKILL escalation against a reparented
 * child of a dead supervisor.
 */
final class StubbornJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $duration = (int) ($context->params['duration'] ?? 20);
        $end = time() + $duration;

        while (time() < $end) {
            @time_nanosleep(1, 0); // a signal interrupts the sleep; the loop just continues
        }

        if (! empty($context->params['marker'])) {
            file_put_contents((string) $context->params['marker'], 'finished');
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
