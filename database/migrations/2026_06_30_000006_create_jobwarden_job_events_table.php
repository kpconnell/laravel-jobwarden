<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit: the "how it got there" record. Never updated, only
        // inserted, in the same transaction as the state change (spec §4.4/§11).
        $this->schema()->create($this->table('job_events'), function (Blueprint $table): void {
            $table->bigIncrements('id');           // monotonic ordering column
            $table->uuid('job_id');
            $table->uuid('attempt_id')->nullable();
            $table->string('level');               // job|attempt|batch
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('actor_type');          // worker|supervisor|scheduler|reaper|operator|system
            $table->string('actor_id')->nullable();
            $table->string('reason')->nullable();
            $table->json('context')->nullable();   // error context, process snapshot, fencing token
            $table->timestampTz('created_at', 6)->nullable()->index();

            $table->index(['job_id', 'id']);
            $table->index('attempt_id');

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_events'));
    }
};
