<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // The sixth progress bucket (JobStateBuckets): members an operator
        // skipped. Kept apart from succeeded_count so the batch summary and the
        // dashboard can say how much of a "succeeded" batch was actually run.
        // Existing rows take 0, so an upgrade keeps the partition invariant.
        $this->schema()->table($this->table('batches'), function (Blueprint $table): void {
            $table->integer('skipped_count')->default(0);
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('batches'), function (Blueprint $table): void {
            $table->dropColumn('skipped_count');
        });
    }
};
