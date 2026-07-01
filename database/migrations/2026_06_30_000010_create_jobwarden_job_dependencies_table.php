<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // DAG edges (spec §4.10). A job is admitted only when all depends_on jobs
        // are satisfied.
        $this->schema()->create($this->table('job_dependencies'), function (Blueprint $table): void {
            $table->uuid('job_id');
            $table->uuid('depends_on_job_id');

            $table->primary(['job_id', 'depends_on_job_id']);
            $table->index('depends_on_job_id');

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
            $table->foreign('depends_on_job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_dependencies'));
    }
};
