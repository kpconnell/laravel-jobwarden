<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for every JobWarden model: pins the dedicated connection (spec §2) and
 * applies the configurable table prefix to the unprefixed $baseTable.
 */
abstract class JobWardenModel extends Model
{
    /** Unprefixed table name (e.g. 'jobs'); subclasses set this. */
    protected string $baseTable;

    public function getConnectionName(): ?string
    {
        return config('jobwarden.connection');
    }

    public function getTable(): string
    {
        return ((string) config('jobwarden.table_prefix', 'jobwarden_')).$this->baseTable;
    }
}
