@php
    use JobWarden\States\BatchState;

    $stateHue = fn (string $s) => match ($s) {
        'succeeded' => 'green', 'failed' => 'red', 'running', 'queued', 'dispatched' => 'blue',
        'retrying' => 'amber', 'orphaned' => 'purple', 'canceled', 'stopped' => 'gray', default => 'slate',
    };
@endphp
<div class="view" wire:poll.{{ config('jobwarden.dashboard.poll', '10s') }}>
    <div class="detail-head pad-b">
        <a class="backlink" href="{{ route('jobwarden.batches') }}" wire:navigate>← Batches</a>
        <div class="detail-title-row">
            <div class="grow">
                <div class="detail-title">
                    <span class="name mono">{{ $batch->name ?? 'batch' }}</span>
                    @include('jobwarden::partials.state-badge', ['state' => $batch->state])
                    @php($policyHue = match ($batch->failure_policy) { 'fail_fast' => 'red', 'threshold' => 'amber', default => 'slate' })
                    <span class="badge h-{{ $policyHue }}">policy: {{ $batch->failure_policy }}{{ $batch->failure_policy === 'threshold' && $batch->failure_threshold ? ' ('.$batch->failure_threshold.')' : '' }}</span>
                </div>
                <div class="detail-sub">{{ $batch->id }}@if ($batch->type) · type {{ $batch->type }}@endif @if ($batch->created_by) · {{ $batch->created_by }}@endif</div>
            </div>
            <div class="detail-actions">
                @if (in_array($batch->state, [BatchState::Pending, BatchState::Running], true))
                    <button type="button" class="btn btn-red" wire:click="cancel" wire:confirm="Cancel this batch and all its non-terminal members?">Cancel batch</button>
                @endif
            </div>
        </div>

        <div class="count-chips">
            @foreach (['pending' => $batch->pending_count, 'running' => $batch->running_count, 'succeeded' => $batch->succeeded_count, 'failed' => $batch->failed_count, 'canceled' => $batch->canceled_count] as $label => $n)
                <div class="count-chip">
                    <span class="sdot h-{{ $stateHue($label) }}" style="width:6px;height:6px"></span>
                    <span>{{ $label }}</span><b>{{ number_format((int) $n) }}</b>
                </div>
            @endforeach
            <div class="count-chip"><span>total</span><b>{{ number_format((int) $batch->total_jobs) }}</b></div>
        </div>
    </div>

    <div class="tabs" style="padding:0 20px;border-bottom:1px solid var(--border);flex:none">
        <button type="button" class="tab {{ $tab === 'graph' ? 'on' : '' }}" wire:click="$set('tab', 'graph')">Graph</button>
        <button type="button" class="tab {{ $tab === 'jobs' ? 'on' : '' }}" wire:click="$set('tab', 'jobs')">Member jobs</button>
    </div>

    <div class="tab-body">
        @if ($tab === 'graph' && $dag !== null)
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px">
                @foreach (['succeeded', 'running', 'pending', 'failed', 'canceled'] as $lg)
                    <div style="display:flex;align-items:center;gap:6px">
                        <span class="dag-node fill-{{ $stateHue($lg) }}" style="width:16px;height:11px"></span>
                        <span style="font-size:11px;color:var(--fg-2)">{{ $lg }}</span>
                    </div>
                @endforeach
                <div style="display:flex;align-items:center;gap:6px">
                    <span class="dag-node fill-gray dim" style="width:16px;height:11px"></span>
                    <span style="font-size:11px;color:var(--fg-2)">canceled downstream of a failure</span>
                </div>
                <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
                    <span class="cell-dim">{{ number_format($dag['nodeCount']) }} nodes</span>
                    @if ($dag['nodeCount'] > 600 && count($expandedLanes ?? []) < count($dag['lanes']))
                        <button type="button" class="btn sm" wire:click="expandAll" wire:confirm="This batch has {{ number_format($dag['nodeCount']) }} nodes — expanding every lane renders all of them. Continue?">Expand all</button>
                    @elseif (count($expandedLanes ?? []) < count($dag['lanes']))
                        <button type="button" class="btn sm" wire:click="expandAll">Expand all</button>
                    @endif
                    @if (count($expandedLanes ?? []) > 0)
                        <button type="button" class="btn sm" wire:click="collapseAll">Collapse all</button>
                    @endif
                </div>
            </div>

            @if ($dag['lanes'] === [])
                <div class="empty">This batch has no member jobs.</div>
            @else
                @php($cols = '160px repeat('.($dag['maxDepth'] + 1).', minmax(44px, 1fr))')
                <div class="dag-wrap">
                    <div class="dag-row" style="grid-template-columns:{{ $cols }}">
                        <div class="dag-cornhead">lane</div>
                        @foreach ($dag['stages'] as $stage)
                            <div class="dag-colhead" title="{{ $stage }}">{{ $stage }}</div>
                        @endforeach
                    </div>
                    @foreach ($dag['lanes'] as $l)
                        @php($expanded = in_array($l['key'], $expandedLanes ?? [], true))
                        @if ($expanded)
                            <div class="dag-row" style="grid-template-columns:{{ $cols }}" wire:key="lane-{{ md5($l['key']) }}">
                                <button type="button" class="dag-lanehead" wire:click="toggleLane('{{ $l['key'] }}')" title="Collapse lane">
                                    {{ $l['label'] }} {{ $l['failing'] ? '●' : '' }}
                                </button>
                                @php($byDepth = collect($l['nodes'])->groupBy('depth'))
                                @for ($d = 0; $d <= $dag['maxDepth']; $d++)
                                    <div class="dag-cell {{ $d > 0 ? 'linked' : '' }}">
                                        @foreach ($byDepth->get($d, collect()) as $n)
                                            <a class="dag-node fill-{{ $stateHue($n['state']) }} {{ $n['dimmed'] ? 'dim' : '' }} {{ $n['state'] === 'running' ? 'pulse' : '' }}"
                                               href="{{ route('jobwarden.jobs.show', $n['id']) }}" wire:navigate
                                               title="{{ $n['label'] }} · {{ $n['state'] }}{{ $n['dimmed'] ? ' · downstream of a failure' : '' }}"></a>
                                        @endforeach
                                    </div>
                                @endfor
                            </div>
                        @else
                            @php($laneTotal = max(1, array_sum($l['states'])))
                            <div class="dag-row" style="grid-template-columns:160px 1fr" wire:key="lane-{{ md5($l['key']) }}">
                                <button type="button" class="dag-lanehead" wire:click="toggleLane('{{ $l['key'] }}')" title="Expand lane">
                                    {{ $l['label'] }} {{ $l['failing'] ? '●' : '' }}
                                </button>
                                <div class="dag-collapsed">
                                    <div class="bar thin">
                                        @foreach ($l['states'] as $s => $n)
                                            <div class="fill-{{ $stateHue($s) }}" style="width:{{ round($n / $laneTotal * 100, 2) }}%" title="{{ $s }} · {{ $n }}"></div>
                                        @endforeach
                                    </div>
                                    <span class="n">{{ array_sum($l['states']) }} jobs · click to expand</span>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="footnote">
                    Lanes are the batch's independent sub-chains (connected components of the dependency edges); columns are dependency depth —
                    a node runs only after everything to its left in its lane. A red node failed; the dimmed tail behind it is the work a
                    {{ $batch->failure_policy }} policy canceled downstream. Click a node to open that job.
                </div>
            @endif
        @elseif ($tab === 'jobs' && $members !== null)
            <div style="border:1px solid var(--border);border-radius:9px;overflow:hidden">
                @forelse ($members as $job)
                    <div class="tbl-row members-grid click" style="padding-top:6px;padding-bottom:6px" data-jw-href="{{ route('jobwarden.jobs.show', $job->id) }}" wire:key="member-{{ $job->id }}">
                        @include('jobwarden::partials.state-badge', ['state' => $job->state])
                        <div class="cell-main">
                            <div class="t">{{ $job->name ?? class_basename($job->job_class) }}</div>
                            <div class="s">{{ \Illuminate\Support\Str::substr($job->id, 0, 13) }}…</div>
                        </div>
                        <span class="cell-mono">
                            @if ($job->tags->isNotEmpty()){{ $job->tags->first()->name }}:{{ $job->tags->first()->value }}@else — @endif
                        </span>
                        <span class="cell-mono">{{ $job->attempt_count }}/{{ $job->max_attempts }}</span>
                        <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $job->created_at_ms])</span>
                    </div>
                @empty
                    <div class="empty" style="margin:14px">This batch has no member jobs.</div>
                @endforelse
            </div>
            @if ($members->hasPages())
                {{ $members->links('jobwarden::partials.pagination') }}
            @endif
        @endif
    </div>
</div>
