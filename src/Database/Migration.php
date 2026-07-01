<?php

declare(strict_types=1);

namespace JobWarden\Database;

use Illuminate\Database\Migrations\Migration as BaseMigration;
use Illuminate\Support\Facades\Schema;

/**
 * Base migration that pins every JobWarden table to the dedicated connection
 * (spec §2) and applies the configurable table prefix. Schema builder calls go
 * through schema() so they always target the jobwarden connection.
 */
abstract class Migration extends BaseMigration
{
    public function getConnection(): ?string
    {
        return config('jobwarden.connection');
    }

    protected function prefix(): string
    {
        return (string) config('jobwarden.table_prefix', 'jobwarden_');
    }

    protected function table(string $name): string
    {
        return $this->prefix().$name;
    }

    protected function schema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection($this->getConnection());
    }

    protected function driver(): string
    {
        return $this->schema()->getConnection()->getDriverName();
    }
}
