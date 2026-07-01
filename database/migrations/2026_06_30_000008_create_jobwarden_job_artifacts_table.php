<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Files, dumps, and request/response pairs for support-case review (§4.6).
        $this->schema()->create($this->table('job_artifacts'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->uuid('attempt_id')->nullable();
            $table->string('type');           // file|request|response|report|dump
            $table->string('name');
            $table->string('disk')->nullable(); // Laravel filesystem disk
            $table->string('path')->nullable(); // file path / url (null for inline summaries)
            $table->bigInteger('size_bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->string('content_type')->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('created_at', 6)->nullable();

            $table->index('job_id');
            $table->index('attempt_id');

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('job_artifacts'));
    }
};
