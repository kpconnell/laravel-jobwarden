<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * A stand-in for any connection-holding app singleton: remembers which process
 * constructed it, so a test can tell an inherited master instance (master pid)
 * from one rebuilt fresh inside a fork (child pid) after prefork_forget drops it.
 */
final class ForkProbe
{
    public readonly int $constructedInPid;

    public function __construct()
    {
        $this->constructedInPid = (int) getmypid();
    }
}
