<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('schedules'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('job_class');
            $table->json('params')->nullable();
            $table->string('kind')->default('recurring');     // recurring|one_time
            $table->string('cron_expression')->nullable();
            $table->timestampTz('run_at', 6)->nullable();      // one-time
            $table->string('timezone')->default('UTC');
            $table->boolean('enabled')->default(true);
            // Can a lost run be safely re-run? Drives host-loss recovery of the
            // materialized run: idempotent → retry on another host; otherwise park.
            $table->boolean('idempotent')->default(false);
            $table->integer('max_attempts')->nullable();           // retry budget; null = derived from idempotent
            $table->string('missed_policy')->default('run_latest'); // run_latest|run_all|skip|coalesce
            $table->integer('catch_up_window_sec')->nullable();
            $table->integer('max_catch_up')->nullable();
            $table->string('overlap_policy')->default('skip'); // allow|skip|queue
            $table->smallInteger('priority')->default(0);
            $table->string('owner')->nullable();
            $table->json('tags')->nullable();
            $table->timestampTz('last_evaluated_at', 6)->nullable();
            $table->timestampTz('last_enqueued_for', 6)->nullable();
            $table->timestampTz('next_due_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();

            $table->index(['enabled', 'next_due_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('schedules'));
    }
};
