<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class Batches extends Component
{
    public ?string $flash = null;

    public function cancel(JobWarden $jobwarden, string $id): void
    {
        try {
            $jobwarden->cancelBatch(Batch::findOrFail($id), 'cancel via dashboard', (string) (auth()->id() ?? 'dashboard'));
            $this->flash = 'batch canceled';
        } catch (Throwable $e) {
            $this->flash = 'error: '.$e->getMessage();
        }
    }

    public function render()
    {
        return view('jobwarden::livewire.batches', [
            'batches' => Batch::query()->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }
}
