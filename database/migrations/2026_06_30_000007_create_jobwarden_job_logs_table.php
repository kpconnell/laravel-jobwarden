<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // The LogIndex (spec §4.5): small, queryable index rows over a pluggable
        // LogBodySink. The database sink stores the body inline in body_ref.
        $this->schema()->create($this->table('job_logs'), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('job_id');
            $table->uuid('attempt_id');
            $table->bigInteger('seq');          // monotonic per attempt; ordered tailing cursor
            $table->timestampTz('ts', 6);
            $table->string('level');            // debug|info|notice|warning|error|critical
            $table->string('step')->nullable();
            $table->json('context')->nullable();
            $table->string('body_sink')->default('database'); // database|disk|s3|custom
            $table->text('body_ref')->nullable();             // inline message OR pointer/key
            $table->timestampTz('created_at', 6)->nullable();

            $table->index(['attempt_id', 'seq']);
            $table->index(['job_id', 'ts']);

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
            $table->foreign('attempt_id')->references('id')->on($this->table('job_attempts'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_logs'));
    }
};
