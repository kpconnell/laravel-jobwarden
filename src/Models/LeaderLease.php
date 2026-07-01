<?php

declare(strict_types=1);

namespace JobWarden\Models;

/**
 * Single-row leader lease for the HA global reaper (spec §5.4). Keyed by name.
 * Acquired/refreshed via guarded UPDATE; this model is for reads/inspection.
 */
class LeaderLease extends JobWardenModel
{
    protected string $baseTable = 'leader_leases';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'acquired_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
