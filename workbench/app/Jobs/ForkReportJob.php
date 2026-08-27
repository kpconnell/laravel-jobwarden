<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workbench\App\Support\ForkProbe;

/**
 * Fork-safety observatory: writes a JSON report of the process-level facts a prefork
 * child must get right — its own pid, the DB session it is actually talking on,
 * fresh entropy, and (when the 'workbench.fork-probe' singleton is bound and listed
 * in prefork_forget) the pid the resolved probe instance was CONSTRUCTED in.
 */
final class ForkReportJob implements JobWardenJob
{
    public function __construct(
        private readonly string $report = '',
    ) {
    }

    public function handle(JobContext $context): void
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $backendId = match ($conn->getDriverName()) {
            'pgsql' => (int) $conn->select('SELECT pg_backend_pid() AS id')[0]->id,
            default => (int) $conn->select('SELECT CONNECTION_ID() AS id')[0]->id,
        };

        $probePid = null;
        if (app()->bound('workbench.fork-probe')) {
            $probePid = app('workbench.fork-probe')->constructedInPid;
        }

        // The FACADE path on purpose — real handlers write Cache::/Redis::, not
        // app('cache'). If the facade's static cache survived the fork uncleaned,
        // these report the MASTER'S socket and construction pid.
        $socketLocal = null;
        $socketPid = null;
        if (app()->bound('workbench.socket-probe')) {
            $socketLocal = \Workbench\App\Support\SocketProbeFacade::localName();
            $socketPid = \Workbench\App\Support\SocketProbeFacade::getFacadeRoot()->constructedInPid;
        }

        file_put_contents($this->report, json_encode([
            'attempt_id' => $context->attemptId,
            'child_pid' => getmypid(),
            'db_backend_id' => $backendId,
            'mt_rand' => mt_rand(),
            'random_bytes' => bin2hex(random_bytes(16)),
            'uuid' => (string) Str::uuid(),
            'probe_constructed_pid' => $probePid,
            'socket_local' => $socketLocal,
            'socket_constructed_pid' => $socketPid,
            // Read through the Log FACADE: shared context lives on the LogManager
            // instance, so an inherited master manager still carries the master's
            // stamp while a rebuilt one comes back empty.
            'log_shared_context' => \Illuminate\Support\Facades\Log::sharedContext(),
        ]));
    }

    public function idempotent(): bool
    {
        return true;
    }
}
