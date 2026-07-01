<?php

declare(strict_types=1);

namespace JobWarden\Stamp;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Completes the PHASE-2 process stamp (spec §5.1): after proc_open returns the
 * child, the supervisor writes child_pid / child_start_time / proc_nonce. The
 * write is fencing-guarded so a stamp for a superseded epoch is rejected.
 */
final class ProcessStampWriter
{
    public function phase2(string $attemptId, int $fencingToken, int $childPid, string $childStartTime, string $nonce): bool
    {
        $affected = $this->connection()
            ->table($this->tbl('job_attempts'))
            ->where('id', $attemptId)
            ->where('fencing_token', $fencingToken)
            ->update([
                'child_pid' => $childPid,
                'child_start_time' => $childStartTime,
                'proc_nonce' => $nonce,
                'updated_at' => $this->connection()->raw('CURRENT_TIMESTAMP'),
            ]);

        return $affected === 1;
    }

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
