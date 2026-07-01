<?php

declare(strict_types=1);

namespace JobWarden\Claim;

/**
 * The claiming supervisor's identity, written as the PHASE-1 process stamp at
 * claim time (spec §5.1): host_id + supervisor PID/start-time. The child half of
 * the stamp (child_pid/start_time/nonce) is completed in phase 2 after spawn (P5).
 */
final class WorkerContext
{
    public function __construct(
        public readonly string $workerId,
        public readonly string $hostId,
        public readonly ?string $hostname = null,
        public readonly ?int $supervisorPid = null,
        public readonly ?int $supervisorStartTime = null,
        public readonly string $lane = 'default',
    ) {
    }

    /** @return array<string,mixed> snapshot for the job_events context */
    public function snapshot(): array
    {
        return [
            'worker_id' => $this->workerId,
            'host_id' => $this->hostId,
            'hostname' => $this->hostname,
            'supervisor_pid' => $this->supervisorPid,
            'supervisor_start_time' => $this->supervisorStartTime,
        ];
    }
}
