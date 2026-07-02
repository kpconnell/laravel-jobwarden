<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JobWarden\Support\SqlTime;

/**
 * Base for every JobWarden model: pins the dedicated connection (spec §2) and
 * applies the configurable table prefix to the unprefixed $baseTable.
 */
abstract class JobWardenModel extends Model
{
    /** Unprefixed table name (e.g. 'jobs'); subclasses set this. */
    protected string $baseTable;

    /**
     * Datetime columns this model exposes to the dashboard. The display layer needs each
     * as an absolute epoch so the browser can render it in the viewer's own timezone;
     * subclasses override. See scopeWithDisplayEpochs().
     *
     * @var list<string>
     */
    protected array $displayTimes = [];

    public function getConnectionName(): ?string
    {
        return config('jobwarden.connection');
    }

    public function getTable(): string
    {
        return ((string) config('jobwarden.table_prefix', 'jobwarden_')).$this->baseTable;
    }

    /**
     * Append a `<col>_ms` Unix-epoch-milliseconds column for each declared display time,
     * computed in SQL (not from the loaded Carbon, which is untrustworthy under app↔DB
     * timezone drift) so the browser gets the true instant regardless of either timezone.
     */
    public function scopeWithDisplayEpochs(Builder $query): Builder
    {
        if ($this->displayTimes === []) {
            return $query;
        }

        $query->addSelect('*');
        foreach ($this->displayTimes as $col) {
            $query->addSelect(DB::raw(SqlTime::epochMsExpr($query->getConnection(), $col)." as {$col}_ms"));
        }

        return $query;
    }
}
