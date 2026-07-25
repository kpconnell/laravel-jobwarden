<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cross-batch DAG edge (spec §4.11): a job gated on a whole batch. Composite
 * key (job_id, depends_on_batch_id); reads only — edges are written via the
 * query builder.
 */
class JobBatchDependency extends JobWardenModel
{
    protected string $baseTable = 'job_batch_dependencies';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'job_id';

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function dependsOnBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'depends_on_batch_id');
    }
}
