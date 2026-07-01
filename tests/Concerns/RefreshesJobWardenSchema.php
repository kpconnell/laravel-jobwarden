<?php

declare(strict_types=1);

namespace JobWarden\Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Runs the JobWarden migrations against the dedicated connection and gives each
 * test a clean slate. On SQLite each test already gets a fresh :memory: db; on
 * Postgres the schema persists, so we TRUNCATE between tests.
 */
trait RefreshesJobWardenSchema
{
    protected function setUpJobWardenSchema(): void
    {
        // Migrate against the jobwarden connection itself so the migration
        // repository lives alongside the tables (not on the default, ephemeral
        // connection). migrate:fresh guarantees a clean, current schema each
        // test on the persistent Postgres db and the throwaway SQLite one alike.
        $this->artisan('migrate:fresh', [
            '--database' => config('jobwarden.connection'),
        ])->assertExitCode(0);
    }

    protected function truncateJobWarden(): void
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix');

        // Child-before-parent ordering for the non-cascading engines.
        $tables = [
            'job_dependencies', 'schedule_runs', 'job_logs', 'job_artifacts',
            'job_events', 'job_attempts', 'jobs', 'schedules', 'workers',
            'batches', 'leader_leases',
        ];

        if ($conn->getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn (string $t): string => $prefix.$t, $tables));
            $conn->statement("TRUNCATE {$list} RESTART IDENTITY CASCADE");

            return;
        }

        foreach ($tables as $t) {
            $conn->table($prefix.$t)->delete();
        }
    }

    protected function jobwarden(): \Illuminate\Database\Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    protected function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
