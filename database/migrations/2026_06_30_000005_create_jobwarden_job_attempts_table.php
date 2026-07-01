<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('job_attempts'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->integer('attempt_number');
            $table->string('state'); // dispatched|running|succeeded|failed|orphaned|canceled|stopped
            $table->uuid('worker_id')->nullable();
            // Monotonic per job; the fencing epoch. Stale-token writes are rejected.
            $table->bigInteger('fencing_token');

            // --- process stamp (host_id + supervisor at claim; child after spawn) ---
            $table->string('host_id')->nullable();   // BOOT-STABLE (machine-id + boot_id)
            $table->string('hostname')->nullable();
            $table->integer('supervisor_pid')->nullable();
            $table->bigInteger('supervisor_start_time')->nullable(); // /proc starttime ticks
            $table->integer('child_pid')->nullable();
            $table->bigInteger('child_start_time')->nullable();
            $table->string('proc_nonce')->nullable();

            // --- timing & outcome ---
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('term_signal')->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->json('progress')->nullable();
            $table->json('error')->nullable();
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();

            // At most one attempt per (job, attempt_number) — also the strongest
            // double-claim tripwire (spec §5.1): a duplicate claim collides here.
            $table->unique(['job_id', 'attempt_number']);
            // Local-reaper scan: my host's in-flight attempts.
            $table->index(['host_id', 'state']);
            $table->index('state');

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on($this->table('workers'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_attempts'));
    }
};
