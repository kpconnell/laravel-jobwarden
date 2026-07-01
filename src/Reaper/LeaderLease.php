<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed leader lease (spec §5.4): exactly one global reaper is active
 * at a time by holding a single row updated with WHERE lease_expires_at < now().
 * If the holder dies, the lease expires and another acquirer wins — so there is
 * never reaper-vs-reaper contention on the cross-host scan.
 *
 * Acquisition is the same guarded-UPDATE + affected-rows pattern as everything
 * else (affected==1 ⇒ I am the leader).
 */
final class LeaderLease
{
    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $conn = $this->connection();
        $table = $this->tbl('leader_leases');

        // Ensure the row exists (idempotent).
        $conn->table($table)->insertOrIgnore(['name' => $name]);

        $affected = $conn->table($table)
            ->where('name', $name)
            ->where(function ($q) use ($conn, $owner): void {
                $q->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<', $conn->raw('CURRENT_TIMESTAMP'))
                    ->orWhere('owner', $owner); // refresh my own lease
            })
            ->update([
                'owner' => $owner,
                'lease_expires_at' => $conn->raw(SqlTime::nowPlus($conn, $ttlSeconds)),
                'acquired_at' => $conn->raw('CURRENT_TIMESTAMP'),
                'updated_at' => $conn->raw('CURRENT_TIMESTAMP'),
            ]);

        return $affected === 1;
    }

    public function release(string $name, string $owner): void
    {
        $this->connection()->table($this->tbl('leader_leases'))
            ->where('name', $name)->where('owner', $owner)
            ->update(['owner' => null, 'lease_expires_at' => null, 'updated_at' => $this->connection()->raw('CURRENT_TIMESTAMP')]);
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
