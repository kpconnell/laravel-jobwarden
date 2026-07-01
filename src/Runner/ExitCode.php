<?php

declare(strict_types=1);

namespace JobWarden\Runner;

/**
 * Child (jobwarden:run) exit codes. The supervisor maps a terminating signal
 * (term_signal) independently of these.
 */
final class ExitCode
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    /** Handler asked to stop gracefully (Terminable). */
    public const GRACEFUL_STOP = 75;

    /** Token/state moved on before the child could start — refused, wrote nothing authoritative. */
    public const STALE_TOKEN = 78;
}
