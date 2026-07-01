<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Process\ProcessStamp;

final class StampVerdict
{
    public function __construct(
        public readonly ProcessStamp $stamp,
        public readonly bool $supervisorAlive,
        public readonly bool $childAlive,
    ) {
    }

    /** Healthy: the owning supervisor is alive and minding its child. */
    public function isHealthy(): bool
    {
        return $this->supervisorAlive;
    }

    /** The dangerous case: supervisor gone, but the child reparented to init and lives on. */
    public function isReparented(): bool
    {
        return ! $this->supervisorAlive && $this->childAlive;
    }
}
