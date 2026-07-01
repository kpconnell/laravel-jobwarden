<?php

declare(strict_types=1);

namespace JobWarden\Models\Concerns;

use Symfony\Component\Uid\Uuid;

/**
 * UUIDv7 string primary keys (spec §2: sortable, distributed generation, good
 * index locality on the hot claim path — unlike random UUIDv4).
 */
trait HasUuidv7
{
    public function initializeHasUuidv7(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }

    public static function bootHasUuidv7(): void
    {
        static::creating(function ($model): void {
            $key = $model->getKeyName();
            if (empty($model->{$key})) {
                $model->{$key} = (string) Uuid::v7();
            }
        });
    }

    public static function newUuidv7(): string
    {
        return (string) Uuid::v7();
    }
}
