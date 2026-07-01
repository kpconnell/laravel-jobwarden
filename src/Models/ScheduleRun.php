<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRun extends JobWardenModel
{
    use HasUuidv7;

    protected string $baseTable = 'schedule_runs';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurrence_time' => 'datetime',
            'detected_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
