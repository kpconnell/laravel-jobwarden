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

    public function requestDrain(): void
    {
        $this->draining = true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }
}
