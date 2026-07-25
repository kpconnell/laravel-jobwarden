<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Cross-batch DAG edges (spec §4.11): a job gated on a whole BATCH.
        // `on_success` requires the upstream batch to reach `succeeded`;
        // `on_completion` requires it terminal AND quiescent — a failed batch
        // with a spared finalizer subtree still in flight has not finished.
        // Named edge_condition like job_dependencies (CONDITION is reserved on
        // MySQL/MariaDB).
        $this->schema()->create($this->table('job_batch_dependencies'), function (Blueprint $table): void {
            $table->uuid('job_id');
            $table->uuid('depends_on_batch_id');
            $table->string('edge_condition', 16)->default('on_success');

            // The PK (leading job_id) serves the admission EXISTS probe; the
            // secondary index serves the doom/revive cascade, which probes by
            // upstream batch.
            $table->primary(['job_id', 'depends_on_batch_id']);
            $table->index('depends_on_batch_id');

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
            $table->foreign('depends_on_batch_id')->references('id')->on($this->table('batches'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_batch_dependencies'));
    }
};
