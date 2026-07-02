<?php

declare(strict_types=1);

namespace JobWarden\Worker;

use JobWarden\Models\Worker;
use JobWarden\Process\Contracts\HostIdentity;

/**
 * Registers and heartbeats process rows (supervisors, schedulers, reapers).
 * `incarnation` is monotonic per (re)start so a rebooted host's new processes
 * are distinguishable from the dead ones that shared its host_id.
 *
 * EVERY time column here (started_at / heartbeat_at / stopped_at) is stamped from the
 * DB clock (CURRENT_TIMESTAMP), never Carbon::now(). The reapers compare heartbeat_at
 * against the DB clock (see SqlTime), and a local-reaper row's heartbeat_at *is* the
 * host lease — so writing it with the app clock makes a just-written beat look stale
 * under any app.timezone other than the DB's, and the global reaper would then orphan
 * every live worker on its first scan. Coordination time is DB time, on both the write
 * and the compare.
 */
final class WorkerRegistry
{
    public function __construct(private readonly HostIdentity $host)
    {
    }

    public function register(string $role, ?int $capacity = null, array $meta = []): Worker
    {
        $worker = Worker::create([
            'role' => $role,
            'host_id' => $this->host->hostId(),
            'hostname' => $this->host->hostname(),
            'pid' => getmypid(),
            'incarnation' => $this->nextIncarnation(),
            'state' => 'active',
            'capacity' => $capacity,
            'current_load' => 0,
            'php_version' => PHP_VERSION,
            'meta' => $meta === [] ? null : $meta,
        ]);

        // Stamp started_at + heartbeat_at from the DB clock (see class docblock).
        $this->stampDbClock($worker, ['started_at', 'heartbeat_at']);

        return $worker->refresh();
    }

    public function heartbeat(Worker $worker, ?int $load = null): void
    {
        $load = $load ?? $worker->current_load;

        // Self-heal: if the row vanished (pruned, or a dev `migrate:fresh` while
        // running), re-insert it under the SAME id so in-flight attempts' worker_id FK
        // stays valid and claiming doesn't wedge. heartbeat_at is (re)stamped from the
        // DB clock just below regardless of what this re-insert wrote.
        if (! Worker::query()->whereKey($worker->getKey())->exists()) {
            $worker->exists = false;
            $worker->current_load = $load;
            $worker->save();
        }

        $this->stampDbClock($worker, ['heartbeat_at'], ['current_load' => $load]);
        $worker->current_load = $load;
    }

    public function setState(Worker $worker, string $state, ?string $signal = null): void
    {
        $worker->forceFill([
            'state' => $state,
            'last_signal' => $signal ?? $worker->last_signal,
        ])->save();

        if (in_array($state, ['stopped', 'dead'], true)) {
            $this->stampDbClock($worker, ['stopped_at']);
        }
    }

    /**
     * Write the given timestamp columns from the DB clock (CURRENT_TIMESTAMP). Uses the
     * query builder so the raw expression lands verbatim, bypassing the model's datetime
     * casts (which would otherwise try to parse the expression as a date).
     *
     * @param  list<string>  $timestampColumns
     * @param  array<string, mixed>  $extra
     */
    private function stampDbClock(Worker $worker, array $timestampColumns, array $extra = []): void
    {
        $conn = $worker->getConnection();

        $updates = $extra;
        foreach ($timestampColumns as $col) {
            $updates[$col] = $conn->raw('CURRENT_TIMESTAMP');
        }

        $conn->table($worker->getTable())
            ->where($worker->getKeyName(), $worker->getKey())
            ->update($updates);
    }

    private function nextIncarnation(): int
    {
        // Monotonic-enough: ms since epoch. New on every process (re)start.
        return (int) round(microtime(true) * 1000);
    }
}
