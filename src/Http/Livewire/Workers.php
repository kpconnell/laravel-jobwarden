<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Worker;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('jobwarden::layout')]
final class Workers extends Component
{
    public bool $all = false;

    public function render()
    {
        $workers = Worker::query()
            ->when(! $this->all, fn ($q) => $q->whereIn('state', ['starting', 'active', 'draining']))
            ->orderBy('role')->orderByDesc('heartbeat_at')->withDisplayEpochs()->get();

        return view('jobwarden::livewire.workers', ['workers' => $workers]);
    }
}
