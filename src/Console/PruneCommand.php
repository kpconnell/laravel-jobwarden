<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Support\SqlTime;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Retention cleanup (spec §10.1). Deletes terminal jobs past retention (cascading
 * to their attempts/events/logs/artifacts), plus dead worker rows and old
 * schedule_runs. --dry-run reports counts without deleting.
 */
final class PruneCommand extends Command
{
    protected $signature = 'jobwarden:prune {--dry-run : count only, delete nothing}';

    protected $description = 'Delete old terminal jobs/logs/events and dead workers per retention policy.';

    private const TERMINAL = ['succeeded', 'failed', 'canceled', 'stopped'];

    public function handle(): int
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $dry = (bool) $this->option('dry-run');
        $retention = (array) config('jobwarden.retention');

        $jobs = $conn->table($this->tbl('jobs'))
            ->whereIn('state', self::TERMINAL)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $conn->raw(SqlTime::nowMinus($conn, ((int) ($retention['jobs_days'] ?? 30)) * 86400)));

        $workers = $conn->table($this->tbl('workers'))
            ->whereIn('state', ['stopped', 'dead'])
            ->whereNotNull('stopped_at')
            ->where('stopped_at', '<', $conn->raw(SqlTime::nowMinus($conn, ((int) ($retention['workers_days'] ?? 7)) * 86400)));

        $scheduleRuns = $conn->table($this->tbl('schedule_runs'))
            ->where('created_at', '<', $conn->raw(SqlTime::nowMinus($conn, ((int) ($retention['schedule_runs_days'] ?? 90)) * 86400)));

        if ($dry) {
            $this->table(['target', 'would delete'], [
                ['terminal jobs (+cascade)', $jobs->count()],
                ['dead workers', $workers->count()],
                ['schedule_runs', $scheduleRuns->count()],
            ]);

            return self::SUCCESS;
        }

        // Jobs cascade to attempts/events/logs/artifacts via FK ON DELETE CASCADE.
        $jobsDeleted = $this->chunkDelete($conn, $jobs);
        $workersDeleted = $workers->delete();
        $runsDeleted = $scheduleRuns->delete();

        $this->info("pruned: {$jobsDeleted} jobs (+cascade), {$workersDeleted} workers, {$runsDeleted} schedule_runs");

        return self::SUCCESS;
    }

    private function chunkDelete(Connection $conn, $query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += $conn->table($this->tbl('jobs'))->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $total;
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
