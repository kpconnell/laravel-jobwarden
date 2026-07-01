<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DAG edge (spec §4.10). Composite key (job_id, depends_on_job_id); reads only —
 * edges are written via the query builder.
 */
class JobDependency extends JobWardenModel
{
    protected string $baseTable = 'job_dependencies';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'job_id';

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'depends_on_job_id');
    }
}
