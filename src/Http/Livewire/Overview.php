<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\Worker;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('jobwarden::layout')]
final class Overview extends Component
{
    public function render()
    {
        $byState = Job::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state');

        return view('jobwarden::livewire.overview', [
            'states' => $byState,
            'byLane' => Job::query()->groupBy('lane')->selectRaw('lane, count(*) as c')->pluck('c', 'lane'),
            'workers' => Worker::query()->whereIn('state', ['starting', 'active', 'draining'])
                ->groupBy('role')->selectRaw('role, count(*) as c')->pluck('c', 'role'),
            'batches' => Batch::query()->whereIn('state', ['running'])->count(),
            'schedules' => Schedule::query()->where('enabled', true)->count(),
            'recent' => Job::query()->orderByDesc('created_at')->limit(10)->withDisplayEpochs()->get(),
            'attention' => (int) ($byState['orphaned'] ?? 0) + (int) ($byState['failed'] ?? 0),
        ]);
    }
}
