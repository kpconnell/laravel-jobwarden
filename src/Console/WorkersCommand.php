<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Models\Worker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        $query = Worker::query()->orderBy('role')->orderByDesc('heartbeat_at');

        if (! $this->option('all')) {
            $query->whereIn('state', ['starting', 'active', 'draining']);
        }
        if ($role = $this->option('role')) {
            $query->where('role', $role);
        }

        $now = Carbon::now();
        $rows = $query->get()->map(fn (Worker $w): array => [
            substr((string) $w->id, 0, 8),
            $w->role,
            $w->state,
            $w->host_id ? substr($w->host_id, 0, 8) : '-',
            $w->pid ?? '-',
            $w->current_load.'/'.($w->capacity ?? '∞'),
            $w->heartbeat_at ? ((int) round(abs($now->diffInSeconds($w->heartbeat_at)))).'s ago' : '-',
            $w->last_signal ?? '-',
        ])->all();

        $this->table(['id', 'role', 'state', 'host', 'pid', 'load', 'heartbeat', 'signal'], $rows);

        return self::SUCCESS;
    }
}
