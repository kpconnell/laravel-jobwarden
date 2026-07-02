<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;

/**
 * Process registry: supervisors, schedulers, reapers, local reapers (spec §4.9).
 * A local_reaper row's heartbeat_at IS the host lease.
 */
class Worker extends JobWardenModel
{
    use HasUuidv7;

    protected string $baseTable = 'workers';

    protected $guarded = [];

    /** @var list<string> */
    protected array $displayTimes = ['heartbeat_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'incarnation' => 'integer',
            'capacity' => 'integer',
            'current_load' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }
}
