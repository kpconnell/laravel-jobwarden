<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

/**
 * A spawned jobwarden:run child: the proc_open resource plus the metadata the
 * supervisor needs to complete the phase-2 stamp, signal it, and classify its
 * exit (Tier 1).
 */
final class ChildHandle
{
    public ?int $exitCode = null;

    public ?int $termSignal = null;

    public bool $reaped = false;

    public bool $stopRequested = false;

    public ?float $stopRequestedAt = null;

    public function __construct(
        public readonly mixed $process,      // proc_open resource (null for a pcntl fork)
        public readonly int $pid,
        public readonly string $attemptId,
        public readonly string $jobId,
        public readonly int $fencingToken,
        public readonly float $startedAt,
        public readonly mixed $logHandle = null,
        // prefork children have no proc_open resource: they are reaped by pcntl_waitpid
        // on $pid, and they redirect their own stdout/stderr (no logHandle here).
        public readonly bool $isFork = false,
    ) {
    }

    public function durationMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
