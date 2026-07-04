<?php

declare(strict_types=1);

namespace JobWarden\Models;

use JobWarden\Models\Concerns\HasUuidv7;
use JobWarden\Models\Concerns\StateGuarded;
use JobWarden\States\JobState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Job (Run): durable intent and verdict that outlive any single execution.
 */
class Job extends JobWardenModel
{
    use HasUuidv7;
    use StateGuarded;

    protected string $baseTable = 'jobs';

    protected $guarded = [];

    /** @var list<string> */
    protected array $displayTimes = ['created_at', 'started_at', 'finished_at'];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected function casts(): array
    {
        return [
            'state' => JobState::class,
            'params' => 'array',
            'idempotent' => 'boolean',
            'priority' => 'integer',
            'max_attempts' => 'integer',
            'attempt_count' => 'integer',
            'max_runtime_sec' => 'integer',
            'cancel_requested' => 'boolean',
            'last_error' => 'array',
            'result' => 'array',
            'available_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'created_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(JobAttempt::class, 'job_id');
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(JobAttempt::class, 'current_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobEvent::class, 'job_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(JobLog::class, 'job_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(JobArtifact::class, 'job_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(JobTag::class, 'job_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(JobDependency::class, 'job_id');
    }

    // -- observability read-model scopes (spec §9.1) ----------------------

    public function scopeRunning($query)
    {
        return $query->where('state', JobState::Running->value);
    }

    /** pending | queued | retrying — work that is waiting, not executing. */
    public function scopeWaiting($query)
    {
        return $query->whereIn('state', [JobState::Pending->value, JobState::Queued->value, JobState::Retrying->value]);
    }

    public function scopeFailed($query)
    {
        return $query->where('state', JobState::Failed->value);
    }

    public function scopeOrphaned($query)
    {
        return $query->where('state', JobState::Orphaned->value);
    }

    public function scopeInBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    /**
     * Jobs carrying tag `name`: with the given value, a `prefix*` value (prefix
     * match), or any value when $value is ''. All shapes are served by the
     * job_tags (name, value) index; matched job_ids come back straight off it.
     */
    public function scopeWhereTag($query, string $name, string $value = '')
    {
        $tags = JobTag::query()->select('job_id')->where('name', $name);

        if (str_ends_with($value, '*')) {
            $tags->where('value', 'like', addcslashes(substr($value, 0, -1), '%_\\').'%');
        } elseif ($value !== '') {
            $tags->where('value', $value);
        }

        return $query->whereIn('id', $tags);
    }

    /**
     * Free-text operator search. Whitespace-separated tokens AND together:
     * `name:value` tokens hit the tag index (trailing `*` = prefix match, bare
     * `name:` = "has this tag"); anything else matches the class or job name.
     * e.g. `store:AMAZ date:2025-01* Backfill`
     */
    public function scopeSearch($query, string $q)
    {
        foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $colon = strpos($token, ':');
            if ($colon > 0) {
                $query->whereTag(substr($token, 0, $colon), substr($token, $colon + 1));

                continue;
            }

            $like = '%'.addcslashes($token, '%_\\').'%';
            $query->where(fn ($x) => $x->where('job_class', 'like', $like)->orWhere('name', 'like', $like));
        }

        return $query;
    }

    /**
     * Alive and verified, but past its expected runtime ceiling — surfaced as
     * STUCK for an operator, never auto-reaped (spec §5.4).
     */
    public function scopeStuck($query)
    {
        $conn = $query->getConnection();
        $cutoff = match ($conn->getDriverName()) {
            'pgsql' => "started_at < CURRENT_TIMESTAMP - (max_runtime_sec * interval '1 second')",
            'sqlite' => "started_at < datetime('now', '-' || max_runtime_sec || ' seconds')",
            'mysql', 'mariadb' => 'started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL max_runtime_sec SECOND)',
            default => '1=0',
        };

        return $query->where('state', JobState::Running->value)
            ->whereNotNull('max_runtime_sec')
            ->whereNotNull('started_at')
            ->whereRaw($cutoff);
    }
}
