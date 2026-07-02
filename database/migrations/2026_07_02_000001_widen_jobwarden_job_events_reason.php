<?php

declare(strict_types=1);

use JobWarden\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // `reason` receives composed free text — notably `attempt failed: {exception
        // message}` on the worker failure path — and exception messages are unbounded.
        // As VARCHAR(255), a long message made strict-mode MariaDB/MySQL reject the
        // audit INSERT (error 1406) inside the same transaction as the failure it was
        // recording. MEDIUMTEXT removes the bound (TEXT on pgsql/sqlite).
        $this->schema()->table($this->table('job_events'), function (Blueprint $table): void {
            $table->mediumText('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('job_events'), function (Blueprint $table): void {
            $table->string('reason')->nullable()->change();
        });
    }
};
