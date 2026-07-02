<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use JobWarden\States\BatchState;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends JobWardenModel
{
    use HasUuidv7;

    protected string $baseTable = 'batches';

    protected $guarded = [];

    /** @var list<string> */
    protected array $displayTimes = ['created_at'];

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = 'created_at';

    protected function casts(): array
    {
        return [
            'state' => BatchState::class,
            'params' => 'array',
            'summary' => 'array',
            'failure_threshold' => 'integer',
            'total_jobs' => 'integer',
            'pending_count' => 'integer',
            'running_count' => 'integer',
            'succeeded_count' => 'integer',
            'failed_count' => 'integer',
            'canceled_count' => 'integer',
            'created_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'batch_id');
    }
}
