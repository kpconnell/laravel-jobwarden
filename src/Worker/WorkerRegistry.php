<?php

declare(strict_types=1);

namespace JobWarden\Worker;

use JobWarden\Models\Worker;
use JobWarden\Process\Contracts\HostIdentity;
use Illuminate\Support\Carbon;

/**
 * Registers and heartbeats process rows (supervisors, schedulers, reapers).
 * `incarnation` is monotonic per (re)start so a rebooted host's new processes
 * are distinguishable from the dead ones that shared its host_id.
 */
final class WorkerRegistry
{
    public function __construct(private readonly HostIdentity $host)
    {
    }

    public function register(string $role, ?int $capacity = null, array $meta = []): Worker
    {
        return Worker::create([
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
            'started_at' => Carbon::now(),
            'heartbeat_at' => Carbon::now(),
        ]);
    }

    public function heartbeat(Worker $worker, ?int $load = null): void
    {
        $worker->forceFill([
            'heartbeat_at' => Carbon::now(),
            'current_load' => $load ?? $worker->current_load,
        ]);

        // Self-heal: if the row vanished (pruned, or a dev `migrate:fresh` while
        // running), re-insert it under the SAME id so in-flight attempts'
        // worker_id FK stays valid and claiming doesn't wedge.
        if (! Worker::query()->whereKey($worker->getKey())->exists()) {
            $worker->exists = false;
            $worker->save();

            return;
        }

        $worker->save();
    }

    public function setState(Worker $worker, string $state, ?string $signal = null): void
    {
        $worker->forceFill([
            'state' => $state,
            'last_signal' => $signal ?? $worker->last_signal,
            'stopped_at' => in_array($state, ['stopped', 'dead'], true) ? Carbon::now() : $worker->stopped_at,
        ])->save();
    }

    private function nextIncarnation(): int
    {
        // Monotonic-enough: ms since epoch. New on every process (re)start.
        return (int) round(microtime(true) * 1000);
    }
}
