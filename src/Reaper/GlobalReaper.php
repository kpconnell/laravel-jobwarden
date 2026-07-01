<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Models\JobAttempt;
use JobWarden\States\AttemptState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tier 3 (spec §5.4) — the only timeout in the system. PIDs are useless across
 * hosts, so a dead/partitioned worker can only be caught by its OWN lease going
 * stale. Exactly one global reaper is active (LeaderLease). Detection keys on the
 * per-process identity (worker_id), NOT host_id: host_id is boot-stable and gets
 * reused when a process restarts on the same box, so a fresh incarnation's
 * heartbeat would mask the dead one under a shared host_id. worker_id is a fresh
 * UUID per (re)start — every attempt is already stamped with the worker that
 * claimed it — so a dead incarnation is always distinguishable from its
 * replacement. For each worker whose heartbeat has expired beyond the budget it
 * orphans that worker's in-flight attempts (bumping the fencing token so the dead
 * process is fenced out), then runs recovery — so an idempotent job re-runs
 * elsewhere. (The local reaper still keys on host_id because it /proc-verifies
 * each child and can therefore tell a reused host_id apart; Tier 3 cannot verify
 * across hosts, so it must key on the unique per-process id.)
 */
final class GlobalReaper
{
    public function __construct(
        private readonly LeaderLease $lease,
        private readonly AttemptOrphaner $orphaner,
    ) {
    }

    /** @return bool whether this instance is the leader (and therefore scanned) */
    public function tick(string $reaperId): bool
    {
        $ttl = (int) config('jobwarden.reaper.global_lease_ttl', 15);
        if (! $this->lease->acquire('global_reaper', $reaperId, $ttl)) {
            return false; // another reaper holds the lease
        }

        foreach ($this->deadWorkers() as $worker) {
            $this->reapWorker((string) $worker->id, (string) $worker->host_id, $reaperId);
        }

        return true;
    }

    public function budgetSeconds(): int
    {
        return (int) config('jobwarden.host_lease.heartbeat_interval', 10)
            * (int) config('jobwarden.host_lease.missed_beats', 3);
    }

    /**
     * Workers (job-claiming processes) whose OWN heartbeat has gone stale beyond
     * the budget. Keyed per worker_id — a fresh incarnation under the same
     * host_id cannot mask a dead one, because they are distinct worker rows.
     *
     * @return object[] rows of {id, host_id}
     */
    private function deadWorkers(): array
    {
        $conn = $this->connection();

        return $conn->table($this->tbl('workers'))
            ->select('id', 'host_id')
            ->where('role', 'supervisor')  // only job-claiming workers stamp attempts
            ->whereIn('state', ['active', 'starting', 'draining'])
            ->whereRaw('heartbeat_at < '.SqlTime::nowMinus($conn, $this->budgetSeconds()))
            ->get()
            ->all();
    }

    private function reapWorker(string $workerId, string $hostId, string $reaperId): void
    {
        $conn = $this->connection();

        // Declare this specific dead process's row dead (no skew — DB clock).
        $conn->table($this->tbl('workers'))
            ->where('id', $workerId)
            ->whereIn('state', ['active', 'starting', 'draining'])
            ->update(['state' => 'dead', 'stopped_at' => $conn->raw('CURRENT_TIMESTAMP'), 'last_signal' => 'worker_dead']);

        $attempts = JobAttempt::query()
            ->where('worker_id', $workerId)
            ->whereIn('state', [AttemptState::Dispatched->value, AttemptState::Running->value])
            ->get();

        if ($attempts->isEmpty()) {
            return; // stale worker with no in-flight work — nothing to recover
        }

        Log::warning('global reaper: worker declared dead', [
            'role' => 'global_reaper',
            'reaper_id' => $reaperId,
            'worker_id' => $workerId,
            'host_id' => $hostId,
            'orphaning_attempts' => $attempts->count(),
            'budget_sec' => $this->budgetSeconds(),
        ]);

        foreach ($attempts as $attempt) {
            $this->orphaner->orphan($attempt, $reaperId, (string) $attempt->host_id, 'global', 'worker dead: '.$workerId);
        }
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
