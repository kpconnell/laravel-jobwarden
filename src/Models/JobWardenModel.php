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

    /** Auto-timestamp columns Eloquent set (not the caller) on this insert; DB-clock-restamped in `created`. */
    private array $jwAutoTimeColumns = [];

    /**
     * created_at / updated_at belong on the DB clock, like every other JobWarden time column.
     * Eloquent's automatic timestamps write Carbon::now() in app.timezone; under an app.timezone
     * that differs from the DB session zone that skews them — created_at showed hours off in the
     * dashboard while started_at (DB-clock) read correctly. We restamp them exactly the way the
     * create paths stamp available_at/queued_at: a query-builder write of SqlTime::nowExpr()
     * (Eloquent's datetime cast rejects a raw CURRENT_TIMESTAMP, and a stored Carbon re-serializes
     * in the app timezone — see JobWarden::dispatch()). Centralized here so no create path can omit
     * it (the omission that caused the drift); the write lands in the surrounding insert
     * transaction, so it adds no extra commit/fsync. A caller-supplied created_at/updated_at is
     * left alone — Eloquent's own updateTimestamps() honors it, so an import/replay keeps its value.
     */
    protected static function booted(): void
    {
        // `creating` fires before Eloquent's updateTimestamps(), so a not-yet-dirty timestamp
        // column is one the caller did not supply — i.e. one Eloquent is about to auto-stamp.
        static::creating(function (self $model): void {
            $model->jwAutoTimeColumns = $model->usesTimestamps()
                ? array_values(array_filter(
                    [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()],
                    fn (?string $col): bool => $col !== null && ! $model->isDirty($col),
                ))
                : [];
        });

        static::created(function (self $model): void {
            if ($model->jwAutoTimeColumns === []) {
                return;
            }

            $conn = $model->getConnection();

            $conn->table($model->getTable())
                ->where($model->getKeyName(), $model->getKey())
                ->update(array_fill_keys($model->jwAutoTimeColumns, $conn->raw(SqlTime::nowExpr($conn))));
        });
    }

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
