<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

/**
 * Holds flags set by async signal handlers. Handlers must do NOTHING but flip a
 * flag (no DB, no heavy work) — the supervisor loop acts on them.
 */
final class SignalState
{
    private bool $draining = false;

    private bool $recycle = false;

    /**
     * A stop (SIGTERM/SIGINT, or an operator calling drain()). Supersedes a recycle
     * drain already in progress: the platform is taking the container away, so
     * drain_timeout applies again from here.
     */
    public function requestDrain(): void
    {
        $this->draining = true;
        $this->recycle = false;
    }

    /** PREFORK recycling: drain to rebaseline, not because anything asked us to stop. */
    public function requestRecycleDrain(): void
    {
        if ($this->draining) {
            return; // a real stop is already in progress and outranks a recycle
        }

        $this->draining = true;
        $this->recycle = true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function isRecycleDrain(): bool
    {
        return $this->draining && $this->recycle;
    }
}
