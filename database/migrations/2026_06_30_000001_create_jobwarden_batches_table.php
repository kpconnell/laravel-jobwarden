<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('batches'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('type')->nullable();           // logical batch type / coordinator class
            $table->string('state')->index();             // pending|running|succeeded|failed|partial|canceled|stopped
            $table->string('failure_policy')->default('continue'); // fail_fast|continue|threshold
            $table->integer('failure_threshold')->nullable();
            $table->json('params')->nullable();
            $table->json('summary')->nullable();

            // Progress counters, maintained transactionally on member transitions.
            $table->integer('total_jobs')->default(0);
            $table->integer('pending_count')->default(0);
            $table->integer('running_count')->default(0);
            $table->integer('succeeded_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('canceled_count')->default(0);

            $table->string('created_by')->nullable();
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('batches'));
    }
};
