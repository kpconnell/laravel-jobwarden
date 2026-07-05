<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Batch\BatchDag;
use JobWarden\Http\Livewire\Concerns\JobActionGuards;
use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('jobwarden::layout')]
final class BatchShow extends Component
{
    use JobActionGuards;
    use WithPagination;

    /** Above this many nodes, lanes start collapsed and expand-all warns. */
    private const AUTO_EXPAND_CAP = 120;

    public string $batchId;

    #[Url] public string $tab = 'graph';

    /** @var list<string>|null lane keys currently expanded; null = not yet seeded */
    public ?array $expandedLanes = null;

    public function mount(string $batch): void
    {
        $this->batchId = $batch;
    }

    public function toggleLane(string $key): void
    {
        $this->expandedLanes = in_array($key, $this->expandedLanes ?? [], true)
            ? array_values(array_diff($this->expandedLanes ?? [], [$key]))
            : [...($this->expandedLanes ?? []), $key];
    }

    public function expandAll(): void
    {
        $this->expandedLanes = array_column(BatchDag::build($this->batch())['lanes'], 'key');
    }

    public function collapseAll(): void
    {
        $this->expandedLanes = [];
    }

    public function cancel(JobWarden $jobwarden): void
    {
        try {
            $jobwarden->cancelBatch($this->batch(), 'cancel via dashboard', $this->actor());
            $this->dispatch('jw-toast', message: 'batch canceled');
        } catch (Throwable $e) {
            $this->dispatch('jw-toast', message: 'action failed', detail: $e->getMessage(), tone: 'error');
        }
    }

    private function batch(): Batch
    {
        return Batch::findOrFail($this->batchId);
    }

    public function render()
    {
        if (! in_array($this->tab, ['graph', 'jobs'], true)) {
            $this->tab = 'graph';
        }

        $batch = Batch::query()->withDisplayEpochs()->findOrFail($this->batchId);

        $dag = null;
        $members = null;

        if ($this->tab === 'graph') {
            $dag = BatchDag::build($batch);

            // First render: open the lanes that need eyes; open everything only
            // while the batch is small enough that the payload stays bounded.
            $this->expandedLanes ??= array_column(array_filter($dag['lanes'], fn ($l) => $l['failing']), 'key')
                ?: ($dag['nodeCount'] <= self::AUTO_EXPAND_CAP ? array_column($dag['lanes'], 'key') : []);
        } else {
            $members = $batch->jobs()->orderByDesc('id')->with('tags')->withDisplayEpochs()->paginate(50);
        }

        return view('jobwarden::livewire.batch-show', [
            'batch' => $batch,
            'dag' => $dag,
            'members' => $members,
        ]);
    }
}
