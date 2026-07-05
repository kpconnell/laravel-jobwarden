<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Http\Livewire\Concerns\JobActionGuards;
use JobWarden\Models\Job;
use JobWarden\Operations\OperatorActions;
use JobWarden\Search\TagIndex;
use JobWarden\States\JobState;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('jobwarden::layout')]
final class Jobs extends Component
{
    use JobActionGuards;
    use WithPagination;

    private const PER_PAGE = [25, 50];

    /** @var list<string> multi-select state chips, OR'd together */
    #[Url] public array $states = [];

    #[Url] public string $lane = '';

    #[Url] public string $tag_name = '';

    #[Url] public string $tag_value = '';

    #[Url] public string $batch_id = '';

    #[Url] public string $q = '';

    #[Url] public int $perPage = 25;

    /** @var list<string> selected row ids (client-side selection for bulk actions) */
    public array $selected = [];

    public function updating($name): void
    {
        // Any filter change restarts the result set; selection is per-view.
        if (! str_starts_with((string) $name, 'selected')) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function toggleState(string $state): void
    {
        $this->states = in_array($state, $this->states, true)
            ? array_values(array_diff($this->states, [$state]))
            : [...$this->states, $state];
        $this->resetFiltered();
    }

    public function setLane(string $lane): void
    {
        $this->lane = $this->lane === $lane ? '' : $lane;
        $this->resetFiltered();
    }

    public function clearFilters(): void
    {
        $this->reset('states', 'lane', 'tag_name', 'tag_value', 'batch_id', 'q');
        $this->resetFiltered();
    }

    /** Select/deselect every row on the current page. */
    public function toggleSelectPage(): void
    {
        $ids = $this->query()->paginate($this->clampedPerPage())->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->selected = array_diff($ids, $this->selected) === []
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * One operator action fanned out over the selection. Rows whose state
     * doesn't allow the action are skipped (and reported), matching the API's
     * per-id semantics — each applied action is independently durable/audited.
     */
    public function bulk(string $action, OperatorActions $ops): void
    {
        abort_unless(in_array($action, ['retry', 'restart', 'cancel', 'stop'], true), 400);

        $applied = $skipped = $failed = 0;
        foreach (Job::query()->whereIn('id', $this->selected)->get() as $job) {
            if (! in_array($action, self::allowedActions($job), true)) {
                $skipped++;

                continue;
            }

            try {
                $ops->{$action}($job, "bulk {$action} via dashboard", $this->actor());
                $applied++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->selected = [];
        $this->dispatch(
            'jw-toast',
            message: ucfirst($action).' applied to '.$applied.' job'.($applied === 1 ? '' : 's'),
            detail: trim(($skipped > 0 ? "{$skipped} skipped (wrong state)" : '').($failed > 0 ? " · {$failed} failed" : ''), ' ·'),
            tone: $failed > 0 ? 'error' : 'ok',
        );
    }

    private function resetFiltered(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    private function clampedPerPage(): int
    {
        return in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 25;
    }

    private function query()
    {
        return Job::query()
            ->when($this->states !== [], fn ($x) => $x->whereIn('state', $this->states))
            ->when($this->lane !== '', fn ($x) => $x->where('lane', $this->lane))
            ->when($this->batch_id !== '', fn ($x) => $x->where('batch_id', $this->batch_id))
            ->when($this->tag_name !== '', fn ($x) => $x->whereTag(
                $this->tag_name,
                $this->tag_value === '' ? '' : $this->tag_value.'*', // typeahead semantics: always prefix
            ))
            ->when($this->q !== '', fn ($x) => $x->search($this->q))
            ->orderByDesc('id'); // UUIDv7 ⇒ creation order, served by the PK (no filesort)
    }

    public function render()
    {
        return view('jobwarden::livewire.jobs', [
            'jobs' => $this->query()
                ->with(['tags', 'batch:id,name', 'schedule:id,name'])
                ->withDisplayEpochs()
                ->paginate($this->clampedPerPage()),
            'allStates' => array_map(fn (JobState $s) => $s->value, JobState::cases()),
            'lanes' => Job::query()->select('lane')->distinct()->orderBy('lane')->pluck('lane'),
            'tagNames' => TagIndex::names(50),
            'tagValues' => $this->tag_name === '' ? collect() : TagIndex::values($this->tag_name, $this->tag_value, 30),
        ]);
    }
}
