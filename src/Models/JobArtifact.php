<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobArtifact extends JobWardenModel
{
    use HasUuidv7;

    protected string $baseTable = 'job_artifacts';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'meta' => 'array',
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
