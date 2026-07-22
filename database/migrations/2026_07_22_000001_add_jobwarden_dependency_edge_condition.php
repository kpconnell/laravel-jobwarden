<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Per-edge admit semantics (spec §4.10):
        //   on_success    — the historical rule: satisfied only when the upstream SUCCEEDED.
        //   on_completion — satisfied when the upstream reaches ANY terminal state.
        // Existing rows take the default, so an upgrade changes no behavior. Named
        // `edge_condition` rather than `condition` because CONDITION is a reserved
        // word on MySQL/MariaDB — the builder quotes it, but every ad-hoc operator
        // query over this table would have to as well.
        $this->schema()->table($this->table('job_dependencies'), function (Blueprint $table): void {
            $table->string('edge_condition', 16)->default('on_success');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('job_dependencies'), function (Blueprint $table): void {
            $table->dropColumn('edge_condition');
        });
    }
};
