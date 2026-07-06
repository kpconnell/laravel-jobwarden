<div class="view" wire:poll.{{ config('jobwarden.dashboard.poll', '10s') }}>

    {{-- filter bar --}}
    <div class="filterbar">
        <div class="filterrow">
            <span class="lbl">state</span>
            @foreach ($allStates as $s)
                @php($hue = match ($s) { 'succeeded' => 'green', 'failed' => 'red', 'running', 'queued' => 'blue', 'retrying' => 'amber', 'orphaned' => 'purple', 'canceled', 'stopped' => 'gray', default => 'slate' })
                <button type="button" class="chip {{ in_array($s, $states, true) ? 'on' : '' }}" wire:click="toggleState('{{ $s }}')">
                    <span class="sdot h-{{ $hue }}"></span>{{ $s }}
                </button>
            @endforeach
        </div>
        <div class="filterrow">
            <span class="lbl">lane</span>
            @foreach ($lanes as $l)
                <button type="button" class="chip {{ $lane === $l ? 'on' : '' }}" wire:click="setLane('{{ $l }}')">
                    <span class="sdot h-{{ $l === 'scheduled' ? 'teal' : ($l === 'mission-critical' ? 'red' : 'slate') }}"></span>{{ $l }}
                </button>
            @endforeach
            @if ($tagNames->isNotEmpty())
                <div class="sep"></div>
                <span style="font-size:11px;color:var(--fg-3)">tag</span>
                <select wire:model.live="tag_name">
                    <option value="">name…</option>
                    @foreach ($tagNames as $t)
                        <option value="{{ $t->name }}">{{ $t->name }} ({{ number_format($t->job_count) }})</option>
                    @endforeach
                </select>
                @if ($tag_name !== '')
                    <input list="jw-tagvals" wire:model.live.debounce.300ms="tag_value" placeholder="value · prefix" style="width:120px">
                    <datalist id="jw-tagvals">
                        @foreach ($tagValues as $tv)
                            <option value="{{ $tv->value }}"></option>
                        @endforeach
                    </datalist>
                @endif
            @endif
            @if ($states !== [] || $lane !== '' || $tag_name !== '' || $q !== '' || $batch_id !== '')
                <button type="button" class="btn sm" wire:click="clearFilters">clear</button>
            @endif
            <span class="count">{{ number_format($jobs->total()) }} job{{ $jobs->total() === 1 ? '' : 's' }}</span>
        </div>
        @if ($batch_id !== '')
            <div class="filterrow">
                <span class="lbl"></span>
                <span class="chip on">batch: <span class="mono">{{ \Illuminate\Support\Str::substr($batch_id, 0, 8) }}…</span></span>
                <a class="btn-link" href="{{ route('jobwarden.batches.show', $batch_id) }}" wire:navigate>open batch →</a>
            </div>
        @endif
    </div>

    {{-- bulk bar --}}
    @if (count($selected) > 0)
        <div class="bulkbar">
            <span class="n">{{ count($selected) }} selected</span>
            <div style="display:flex;gap:6px">
                <button type="button" class="btn sm btn-accent" wire:click="bulk('retry')">Retry</button>
                <button type="button" class="btn sm btn-purple" wire:click="bulk('restart')">Restart</button>
                <button type="button" class="btn sm" wire:click="bulk('cancel')" wire:confirm="Cancel the selected jobs?">Cancel</button>
                <button type="button" class="btn sm btn-red" wire:click="bulk('stop')" wire:confirm="Stop the selected jobs?">Stop</button>
            </div>
            <button type="button" class="clear" wire:click="clearSelection">clear</button>
        </div>
    @endif

    {{-- table header --}}
    @php($pageIds = collect($jobs->items())->pluck('id')->map(fn ($id) => (string) $id))
    @php($allChecked = $pageIds->isNotEmpty() && $pageIds->diff($selected)->isEmpty())
    <div class="tbl-head jobs-grid">
        <label class="cb">
            <input type="checkbox" wire:click="toggleSelectPage" @checked($allChecked)>
            <span class="box"><svg width="9" height="9" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.3 2.3 4.5-5" stroke="var(--accent-fg)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </label>
        <span>State</span><span>Job</span><span>Lane</span><span>{{ $tag_name !== '' ? $tag_name : 'Tags' }}</span><span>Att</span><span>Age</span><span>Source</span>
    </div>

    {{-- rows --}}
    <div class="tbl-scroll">
        @forelse ($jobs as $job)
            <div class="tbl-row jobs-grid click" data-jw-href="{{ route('jobwarden.jobs.show', $job->id) }}" wire:key="job-{{ $job->id }}">
                <label class="cb">
                    <input type="checkbox" value="{{ $job->id }}" wire:model.live="selected">
                    <span class="box"><svg width="9" height="9" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.3 2.3 4.5-5" stroke="var(--accent-fg)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                </label>
                @include('jobwarden::partials.state-badge', ['state' => $job->state])
                <div class="cell-main">
                    <div class="t">{{ $job->job_class }}</div>
                    @if ($job->name)
                        <div class="t">{{ $job->name }}</div>
                    @endif
                </div>
                <span>@include('jobwarden::partials.lane-badge', ['lane' => $job->lane])</span>
                <span class="cell-mono">
                    @if ($tag_name !== '')
                        {{ $job->tags->firstWhere('name', $tag_name)?->value ?? '—' }}
                    @elseif ($job->tags->isNotEmpty())
                        {{ $job->tags->first()->name }}:{{ $job->tags->first()->value }}{{ $job->tags->count() > 1 ? ' +'.($job->tags->count() - 1) : '' }}
                    @else
                        —
                    @endif
                </span>
                <span class="cell-mono">{{ $job->attempt_count }}/{{ $job->max_attempts }}</span>
                <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $job->created_at_ms])</span>
                <span class="cell-txt">
                    @if ($job->schedule)
                        schedule: {{ $job->schedule->name }}
                    @elseif ($job->batch)
                        batch: {{ $job->batch->name ?? \Illuminate\Support\Str::substr($job->batch_id, 0, 8) }}
                    @else
                        {{ $job->created_by ?? 'api' }}
                    @endif
                </span>
            </div>
        @empty
            <div class="empty">
                No jobs match the current filters.
                @if ($states !== [] || $lane !== '' || $tag_name !== '' || $q !== '' || $batch_id !== '')
                    <button type="button" class="btn-link" wire:click="clearFilters">Clear filters</button>
                @endif
            </div>
        @endforelse
    </div>

    {{ $jobs->links('jobwarden::partials.pagination', ['perPageControl' => true]) }}
</div>
