<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\Worker;

/** Overview counts for the dashboard landing — a plain associative array. */
final class StatsController
{
    public function __invoke()
    {
        return [
            'jobs' => Job::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state'),
            'jobs_by_lane' => Job::query()->groupBy('lane')->selectRaw('lane, count(*) as c')->pluck('c', 'lane'),
            'batches' => Batch::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state'),
            'schedules' => [
                'enabled' => Schedule::query()->where('enabled', true)->count(),
                'total' => Schedule::query()->count(),
            ],
            'workers' => Worker::query()
                ->whereIn('state', ['starting', 'active', 'draining'])
                ->groupBy('role')->selectRaw('role, count(*) as c')->pluck('c', 'role'),
        ];
    }
}
