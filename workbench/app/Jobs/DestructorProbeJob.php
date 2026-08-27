<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

/**
 * Plants tripwires that ONLY fire if the prefork child runs PHP shutdown machinery:
 * a register_shutdown_function and a destructor-bearing object pinned in a static
 * (so it lives until process teardown). The child's hardExit contract is pcntl_exec —
 * no shutdown functions, no destructors — because a destructor is exactly the code
 * path that would COM_QUIT the master's shared DB socket. If either marker file
 * exists after the job, that contract is broken.
 */
final class DestructorProbeJob implements JobWardenJob
{
    /** Pins the sentinel so its refcount cannot hit zero before process teardown. */
    public static ?object $sentinel = null;

    public function __construct(
        private readonly string $shutdownMarker = '',
        private readonly string $destructorMarker = '',
    ) {
    }

    public function handle(JobContext $context): void
    {
        $shutdownMarker = $this->shutdownMarker;
        register_shutdown_function(static function () use ($shutdownMarker): void {
            file_put_contents($shutdownMarker, 'shutdown ran in pid '.getmypid());
        });

        self::$sentinel = new class($this->destructorMarker)
        {
            public function __construct(private readonly string $marker)
            {
            }

            public function __destruct()
            {
                file_put_contents($this->marker, 'destructor ran in pid '.getmypid());
            }
        };
    }

    public function idempotent(): bool
    {
        return true;
    }
}
