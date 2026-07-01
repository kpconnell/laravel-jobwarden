<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends JobWardenModel
{
    use HasUuidv7;

    protected string $baseTable = 'schedules';

    protected $guarded = [];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'tags' => 'array',
            'enabled' => 'boolean',
            'idempotent' => 'boolean',
            'max_attempts' => 'integer',
            'priority' => 'integer',
            'catch_up_window_sec' => 'integer',
            'max_catch_up' => 'integer',
            'run_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'last_enqueued_for' => 'datetime',
            'next_due_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRun::class, 'schedule_id');
    }
}
