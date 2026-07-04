<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // The admit pass (Admitter) filters `state` + due available_at and orders
        // `priority DESC, available_at ASC`. Nothing served that shape — the claim
        // index leads with `lane`, which the admit pass doesn't filter — so every
        // pass scanned and filesorted the jobs table. Raw SQL for the same reason
        // as the claim index: fluent index() can't express a DESC column.
        $jobs = $this->table('jobs');
        $idx = $this->prefix().'jobs_admit_idx';
        $this->schema()->getConnection()->statement(
            "CREATE INDEX {$idx} ON {$jobs} (state, priority DESC, available_at)"
        );
    }

    public function down(): void
    {
        $this->schema()->table($this->table('jobs'), function (Blueprint $table): void {
            $table->dropIndex($this->prefix().'jobs_admit_idx');
        });
    }
};
