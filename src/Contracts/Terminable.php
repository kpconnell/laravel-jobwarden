<?php

declare(strict_types=1);

namespace JobWarden\Contracts;

/**
 * Optional: a JobWardenJob may implement this to get a window to checkpoint or
 * clean up when the supervisor signals a graceful stop (SIGTERM) before the
 * forced SIGKILL. See spec §6.3.
 */
interface Terminable
{
    public function onTerminate(): void;
}
