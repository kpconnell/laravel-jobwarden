<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Database-backed leader lease for the HA global reaper (spec §5.4): a
        // single row updated with WHERE lease_expires_at < now(), refreshed by
        // the holder; if the holder dies the lease expires and another acquires.
        $this->schema()->create($this->table('leader_leases'), function (Blueprint $table): void {
            $table->string('name')->primary();             // e.g. 'global_reaper'
            $table->string('owner')->nullable();           // worker id of the current leader
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->timestampTz('acquired_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('leader_leases'));
    }
};
