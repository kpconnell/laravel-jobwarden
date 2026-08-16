<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Models\Worker;
use JobWarden\Support\SqlTime;
use Illuminate\Console\Command;

/**
 * The "who is alive" view (spec §9.1): supervisors, schedulers, and reapers, with
 * heartbeat freshness so an operator can spot a stale host lease at a glance.
 */
final class WorkersCommand extends Command
{
    protected $signature = 'jobwarden:workers {--role= : filter by role} {--all : include stopped/dead}';

    protected $description = 'Show registered JobWarden processes and their liveness.';

    public function handle(): int
    {
        // heartbeat_at is written on the DB clock (WorkerRegistry::stampDbClock), so its age
        // has to be measured against that same clock. Reading the column through Eloquent's
        // datetime cast and subtracting Carbon::now() mixes the two frames: MariaDB hands
        // the value back as a bare string the cast parses in app.timezone, so under any
        // app↔DB offset a fresh heartbeat printed as hours stale. withDisplayEpochs() is the
        // existing tz-safe read — it appends heartbeat_at_ms from SqlTime::epochMsExpr().
        $query = Worker::query()->withDisplayEpochs()->orderBy('role')->orderByDesc('heartbeat_at');

        if (! $this->option('all')) {
            $query->whereIn('state', ['starting', 'active', 'draining']);
        }
        if ($role = $this->option('role')) {
            $query->where('role', $role);
        }

        $nowMs = SqlTime::now((new Worker)->getConnection())->getTimestamp() * 1000;
        $rows = $query->get()->map(fn (Worker $w): array => [
            substr((string) $w->id, 0, 8),
            $w->role,
            $w->state,
            $w->host_id ? substr($w->host_id, 0, 8) : '-',
            $w->pid ?? '-',
            $w->current_load.'/'.($w->capacity ?? '∞'),
            $w->heartbeat_at_ms === null ? '-' : ((int) round(abs($nowMs - (float) $w->heartbeat_at_ms) / 1000)).'s ago',
            $w->last_signal ?? '-',
        ])->all();

        $this->table(['id', 'role', 'state', 'host', 'pid', 'load', 'heartbeat', 'signal'], $rows);

        return self::SUCCESS;
    }
}
