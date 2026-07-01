<?php

declare(strict_types=1);

namespace JobWarden\Console\Concerns;

use JobWarden\Models\Job;

trait ResolvesJob
{
    protected function resolveJob(string $idOrPrefix): ?Job
    {
        return Job::where('id', $idOrPrefix)->first()
            ?? Job::where('id', 'like', $idOrPrefix.'%')->orderByDesc('created_at')->first();
    }
}
