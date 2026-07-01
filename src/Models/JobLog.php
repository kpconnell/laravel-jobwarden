<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LogIndex row (spec §4.5). bigint id; seq is the monotonic per-attempt tail
 * cursor. The body lives inline (database sink) or as a pointer (disk/s3).
 */
class JobLog extends JobWardenModel
{
    protected string $baseTable = 'job_logs';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'context' => 'array',
            'ts' => 'datetime',
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
