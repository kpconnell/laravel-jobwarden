<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('workers'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('role');                 // supervisor|scheduler|global_reaper|local_reaper
            $table->string('host_id');              // BOOT-STABLE id (machine-id + boot_id)
            $table->string('hostname')->nullable(); // human-readable only
            $table->integer('pid')->nullable();
            $table->bigInteger('incarnation')->default(0); // monotonic; new on every (re)start
            $table->string('state');                // starting|active|draining|stopped|dead
            $table->integer('capacity')->nullable();
            $table->integer('current_load')->default(0);
            $table->string('app_version')->nullable();
            $table->string('php_version')->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            // THE host lease: the one coarse liveness signal the global reaper watches.
            $table->timestampTz('heartbeat_at', 6)->nullable();
            $table->timestampTz('stopped_at', 6)->nullable();
            $table->string('last_signal')->nullable();

            // Global reaper's expiry scan.
            $table->index(['state', 'heartbeat_at']);
            $table->index(['host_id', 'role']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('workers'));
    }
};
