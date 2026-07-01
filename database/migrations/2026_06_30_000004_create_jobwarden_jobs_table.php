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

            // Cross-engine composite claim index. The claim scan filters by lane
            // (a worker only claims its own lane) + state, so lane leads. Postgres
            // additionally gets a partial index below (the hot path is state='queued').
            $table->index(['lane', 'state', 'priority', 'available_at'], $this->prefix().'jobs_claim_idx');

            $table->foreign('batch_id')->references('id')->on($this->table('batches'))->nullOnDelete();
            $table->foreign('schedule_id')->references('id')->on($this->table('schedules'))->nullOnDelete();
        });

        // Postgres partial index: the claim scan only ever looks at queued rows.
        if ($this->driver() === 'pgsql') {
            $jobs = $this->table('jobs');
            $idx = $this->prefix().'jobs_queued_partial_idx';
            $this->schema()->getConnection()->statement(
                "CREATE INDEX {$idx} ON {$jobs} (lane, priority DESC, available_at) WHERE state = 'queued'"
            );
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('jobs'));
    }
};
