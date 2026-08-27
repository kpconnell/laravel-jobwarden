<?php

declare(strict_types=1);

namespace JobWarden\Events;

/**
 * Dispatched INSIDE a prefork child, after JobWarden has severed everything it owns
 * (stdio, signals, RNG, its DB connection) and dropped the `prefork_forget` container
 * bindings — immediately before the job handler runs.
 *
 * This is the app's after-fork hook (the same contract as Gunicorn's post_fork /
 * Unicorn's after_fork): reset here any connection-holding singleton the container
 * can't name — SDK clients with keep-alive sockets, gRPC channels, custom streams.
 * Listeners registered in the master are inherited copy-on-write, so register once
 * in a service provider and this fires in every child.
 */
final class PreforkChildStarting
{
    public function __construct(
        public readonly string $attemptId,
        public readonly int $childPid,
    ) {
    }
}
