<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use JobWarden\Models\Concerns\StateGuarded;
use JobWarden\States\AttemptState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Attempt: one immutable worker-instance binding (PID, host, lease epoch).
 * Carries the process stamp (spec §4.3).
 */
class JobAttempt extends JobWardenModel
{
    use HasUuidv7;
    use StateGuarded;

    protected string $baseTable = 'job_attempts';

    protected $guarded = [];

    /** @var list<string> */
    protected array $displayTimes = ['started_at', 'finished_at', 'created_at'];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected function casts(): array
    {
        return [
            'state' => AttemptState::class,
            'attempt_number' => 'integer',
            'fencing_token' => 'integer',
            'supervisor_pid' => 'integer',
            'supervisor_start_time' => 'integer',
            'child_pid' => 'integer',
            'child_start_time' => 'integer',
            'exit_code' => 'integer',
            'term_signal' => 'integer',
            'duration_ms' => 'integer',
            'progress' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }
}
