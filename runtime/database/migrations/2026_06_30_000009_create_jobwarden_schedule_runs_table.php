<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Evaluation & occurrence audit (spec §4.8).
        $this->schema()->create($this->table('schedule_runs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('schedule_id');
            $table->timestampTz('occurrence_time', 6);    // the scheduled instant this represents
            $table->timestampTz('detected_at', 6)->nullable();
            $table->string('action');                      // enqueued|skipped|coalesced|overlapped|outside_window
            $table->uuid('job_id')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('evaluator_worker_id')->nullable();
            $table->timestampTz('created_at', 6)->nullable();

            // THE lynchpin of multi-scheduler safety: every occurrence
            // materializes at most once regardless of concurrent evaluators.
            $table->unique(['schedule_id', 'occurrence_time']);

            $table->foreign('schedule_id')->references('id')->on($this->table('schedules'))->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('schedule_runs'));
    }
};
