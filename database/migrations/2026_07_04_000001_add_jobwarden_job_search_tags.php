<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Searchable tags: an assoc map per job (one value per tag name), kept in
        // a narrow indexed table instead of a JSON column so MariaDB can serve
        // tag lookups from a plain B-tree. Values are capped at 200 chars BY
        // DESIGN — tags are search keys, not payload (payload stays in params).
        $this->schema()->create($this->table('job_tags'), function (Blueprint $table): void {
            $table->uuid('job_id');
            $table->string('name', 64);
            $table->string('value', 200);

            $table->primary(['name', 'job_id']);   // one value per tag name per job
            $table->index(['name', 'value']);      // the search index
            $table->index('job_id');               // FK cascade (the PK leads with name)

            $table->foreign('job_id')->references('id')->on($this->table('jobs'))->cascadeOnDelete();
        });

        // The JSON tags column is superseded by the table (production had zero
        // tagged rows). Dropping it keeps a single source of truth.
        $this->schema()->table($this->table('jobs'), function (Blueprint $table): void {
            $table->dropColumn('tags');
        });

        // Class filtering (dashboard dropdown / API exact match) gets its own
        // index — the previous LIKE '%x%' scan had nothing to stand on.
        $this->schema()->table($this->table('jobs'), function (Blueprint $table): void {
            $table->index('job_class', $this->prefix().'jobs_class_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('jobs'), function (Blueprint $table): void {
            $table->dropIndex($this->prefix().'jobs_class_idx');
            $table->json('tags')->nullable();
        });
        $this->schema()->dropIfExists($this->table('job_tags'));
    }
};
