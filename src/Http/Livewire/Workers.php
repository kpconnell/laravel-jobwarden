<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\States\JobState;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('jobwarden::layout')]
final class Workers extends Component
{
    private const LIVE = ['starting', 'active', 'draining'];

    public bool $all = false;

    public function toggleAll(): void
    {
        $this->all = ! $this->all;
    }

    public function render()
    {
        return view('jobwarden::livewire.workers', [
            'workers' => Worker::query()
                ->when(! $this->all, fn ($q) => $q->whereIn('state', self::LIVE))
                ->orderBy('role')->orderByDesc('heartbeat_at')->withDisplayEpochs()->get(),
            'roleCounts' => Worker::query()->whereIn('state', self::LIVE)
                ->groupBy('role')->selectRaw('role, count(*) as c')->orderBy('role')->pluck('c', 'role'),
            'deadSupervisors' => Worker::query()->where('state', 'dead')->where('role', 'supervisor')->count(),
            'laneCoverage' => $this->laneCoverage(),
        ]);
    }

    /**
     * Supervisors per lane, against work waiting in that lane.
     *
     * A lane with queued jobs and NO live supervisor is the one fleet failure the
     * engine cannot report on its own: recovery only ever recovers work, never
     * processes, so a supervisor that dies and is never restarted leaves a
     * perfectly consistent database and a lane that has silently stopped running.
     * Nothing fails, nothing gets stuck — the jobs just queue. This is the view
     * that makes it visible.
     *
     * @return list<array{lane: string, supervisors: int, capacity: int, queued: int}>
     */
    private function laneCoverage(): array
    {
        $supervisors = Worker::query()
            ->whereIn('state', self::LIVE)->where('role', 'supervisor')
            ->get(['capacity', 'meta'])
            ->groupBy(fn (Worker $w): string => (string) ($w->meta['lane'] ?? 'default'));

        $queued = Job::query()->where('state', JobState::Queued->value)
            ->groupBy('lane')->selectRaw('lane, count(*) as c')->pluck('c', 'lane');

        $lanes = $supervisors->keys()->merge($queued->keys())->unique()->sort()->values();

        return $lanes->map(fn (string $lane): array => [
            'lane' => $lane,
            'supervisors' => $supervisors->get($lane)?->count() ?? 0,
            'capacity' => (int) ($supervisors->get($lane)?->sum('capacity') ?? 0),
            'queued' => (int) ($queued[$lane] ?? 0),
        ])->all();
    }
}
