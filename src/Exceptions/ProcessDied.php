<?php

declare(strict_types=1);

namespace JobWarden\Exceptions;

use RuntimeException;

/**
 * Never thrown. This is the `class` of the structured error the supervisor
 * synthesizes onto `job_attempts.error` / `jobs.last_error` when a child dies
 * without reporting an outcome (SIGKILL/OOM, fatal, external kill) — the child
 * is the only writer of those columns on a caught exception, so a killed child
 * would otherwise leave no error artifact at all. A real class (not a bare
 * string) keeps the name greppable and referencable from code and tests.
 */
final class ProcessDied extends RuntimeException
{
}
