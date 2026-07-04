<?php

declare(strict_types=1);

namespace JobWarden\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Searchable tag on a job: one value per tag name (composite PK name+job_id).
 * Rows are written by TagWriter at job creation; reads only after that.
 */
class JobTag extends JobWardenModel
{
    protected string $baseTable = 'job_tags';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'name';

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
