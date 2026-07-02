<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\States\ActorType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row (spec §4.4). bigint monotonic id; never updated.
 */
class JobEvent extends JobWardenModel
{
    protected string $baseTable = 'job_events';

    protected $guarded = [];

    /** @var list<string> */
    protected array $displayTimes = ['created_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(JobAttempt::class, 'attempt_id');
    }
}
