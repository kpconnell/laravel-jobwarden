<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('jobs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable();
            $table->uuid('schedule_id')->nullable();
            $table->string('job_class');                  // handler resolved from the container
            $table->string('name')->nullable();
            $table->string('lane')->default('default');   // claim partition: business fleet vs the scheduled-tier runner
            $table->json('params')->nullable();
            $table->boolean('idempotent')->default(false); // THE guard (spec §3.4)
            $table->string('idempotency_key')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->string('state');                       // pending|queued|running|retrying|orphaned|succeeded|failed|canceled|stopped
            $table->timestampTz('available_at', 6)->nullable(); // not claimable before this
            $table->integer('max_attempts')->default(1);
            $table->integer('attempt_count')->default(0);
            $table->uuid('current_attempt_id')->nullable(); // back-ref; no DB FK (circular)
            $table->integer('max_runtime_sec')->nullable();
            $table->string('backoff_strategy')->nullable(); // fixed|exponential|custom
            $table->boolean('cancel_requested')->default(false); // desired-state flag
            $table->string('cancel_mode')->nullable();      // cancel|stop
            $table->string('cancel_reason')->nullable();
            $table->timestampTz('cancel_requested_at', 6)->nullable();
            $table->json('last_error')->nullable();
            $table->json('result')->nullable();
            $table->json('tags')->nullable();
            $table->string('created_by')->nullable();
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('queued_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();

            // Multiple NULLs are permitted under the SQL standard, so this gives
            // "unique where not null" on pgsql, mysql, and sqlite alike.
            $table->unique('idempotency_key');
            $table->index('batch_id');
            $table->index('schedule_id');

            // The composite claim index is created AFTER the table (raw SQL, below)
            // so it can use a DESCENDING priority column to match the claim's
            // `ORDER BY priority DESC, available_at ASC`. Laravel's fluent index()
            // can't express per-column sort direction.

            $table->foreign('batch_id')->references('id')->on($this->table('batches'))->nullOnDelete();
            $table->foreign('schedule_id')->references('id')->on($this->table('schedules'))->nullOnDelete();
        });

        // Sort-matching composite claim index (all engines). The claim filters by
        // lane + state, then orders `priority DESC, available_at ASC`. The index MUST
        // match that sort direction: otherwise MySQL/MariaDB filesort the ENTIRE
        // queued backlog and the `FOR UPDATE SKIP LOCKED` claim locks every row it
        // sifts — exhausting the InnoDB lock table at scale ("1206: total number of
        // locks exceeds the lock table size"). With the descending column the claim
        // reads in index order and locks only ~claim_batch rows. Descending index
        // columns are supported on MySQL 8.0+, MariaDB 10.8+, SQLite, and Postgres.
        $jobs = $this->table('jobs');
        $claim = $this->prefix().'jobs_claim_idx';
        $this->schema()->getConnection()->statement(
            "CREATE INDEX {$claim} ON {$jobs} (lane, state, priority DESC, created_at ASC)"
        );

        // Postgres additionally gets a partial index — the claim scan only ever
        // looks at queued rows, so a WHERE-filtered index is smaller and hotter.
        if ($this->driver() === 'pgsql') {
            $partial = $this->prefix().'jobs_queued_partial_idx';
            $this->schema()->getConnection()->statement(
                "CREATE INDEX {$partial} ON {$jobs} (lane, priority DESC, created_at) WHERE state = 'queued'"
            );
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('jobs'));
    }
};
