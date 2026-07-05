<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Models\Batch;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('jobwarden::layout')]
final class Batches extends Component
{
    public function render()
    {
        return view('jobwarden::livewire.batches', [
            'batches' => Batch::query()->orderByDesc('created_at')->limit(50)->withDisplayEpochs()->get(),
        ]);
    }
}
