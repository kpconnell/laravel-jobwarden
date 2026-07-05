<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire\Shell;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\Worker;
use Livewire\Component;

/**
 * The shell's left rail: nav with live count badges and the fleet-health
 * footer. Polls on its own (slow) interval so badges stay current on screens
 * that don't poll, without re-rendering the page component.
 */
final class Sidebar extends Component
{
    public function render()
    {
        $jobs = Job::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state');
        $workers = Worker::query()->selectRaw('role, state, count(*) as c')->groupBy('role', 'state')->get();
        $live = $workers->whereIn('state', ['starting', 'active', 'draining']);

        return view('jobwarden::livewire.sidebar', [
            'attention' => (int) ($jobs['failed'] ?? 0) + (int) ($jobs['orphaned'] ?? 0),
            'inFlight' => (int) ($jobs['pending'] ?? 0) + (int) ($jobs['queued'] ?? 0)
                + (int) ($jobs['running'] ?? 0) + (int) ($jobs['retrying'] ?? 0),
            'runningBatches' => Batch::query()->whereIn('state', ['pending', 'running'])->count(),
            'enabledSchedules' => Schedule::query()->where('enabled', true)->count(),
            'liveWorkers' => (int) $live->sum('c'),
            'liveSupervisors' => (int) $live->where('role', 'supervisor')->sum('c'),
            'deadSupervisors' => (int) $workers->where('state', 'dead')->where('role', 'supervisor')->sum('c'),
        ]);
    }
}
