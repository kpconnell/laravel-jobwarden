<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Job;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('jobwarden::layout')]
final class Jobs extends Component
{
    use WithPagination;

    #[Url] public string $state = '';
    #[Url] public string $lane = '';
    #[Url] public string $batch_id = '';
    #[Url] public string $q = '';

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset('state', 'lane', 'batch_id', 'q');
        $this->resetPage();
    }

    public function render()
    {
        $jobs = Job::query()
            ->when($this->state !== '', fn ($x) => $x->where('state', $this->state))
            ->when($this->lane !== '', fn ($x) => $x->where('lane', $this->lane))
            ->when($this->batch_id !== '', fn ($x) => $x->where('batch_id', $this->batch_id))
            ->when($this->q !== '', fn ($x) => $x->where('job_class', 'like', '%'.$this->q.'%'))
            ->orderByDesc('created_at')
            ->withDisplayEpochs()
            ->paginate(25);

        return view('jobwarden::livewire.jobs', ['jobs' => $jobs]);
    }
}
