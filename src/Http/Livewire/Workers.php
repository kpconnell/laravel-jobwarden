<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Worker;
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
        ]);
    }
}
