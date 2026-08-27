<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Support\Facades\Facade;

/**
 * Facade over the 'workbench.socket-probe' binding, so tests can exercise the access
 * path real apps use: Facade::$resolvedInstance caches the resolved root statically,
 * is inherited copy-on-write by a fork, and bypasses the container on every later
 * call — the exact path that defeated prefork_forget before ForkExecutor also
 * cleared the facade cache.
 */
final class SocketProbeFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'workbench.socket-probe';
    }
}
