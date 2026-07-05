<div class="view" wire:poll.{{ config('jobwarden.dashboard.poll', '10s') }}>
    <div class="view-pad">

        {{-- KPI strip --}}
        @php
            $kpis = [
                ['state' => 'running', 'label' => 'Running', 'hue' => 'blue', 'sub' => 'in flight now'],
                ['state' => 'queued', 'label' => 'Queued', 'hue' => 'blue', 'sub' => 'awaiting claim'],
                ['state' => 'retrying', 'label' => 'Retrying', 'hue' => 'amber', 'sub' => 'in backoff'],
                ['state' => 'failed', 'label' => 'Failed', 'hue' => 'red', 'sub' => 'terminal · retryable', 'warn' => 'warn-red'],
                ['state' => 'orphaned', 'label' => 'Orphaned', 'hue' => 'purple', 'sub' => 'parked · indeterminate', 'warn' => 'warn-purple'],
                ['state' => 'succeeded', 'label' => 'Succeeded', 'hue' => 'green', 'sub' => 'lifetime'],
            ];
        @endphp
        <div class="kpi-grid">
            @foreach ($kpis as $k)
                @php($n = (int) ($states[$k['state']] ?? 0))
                <a class="kpi {{ $n > 0 ? ($k['warn'] ?? '') : '' }}" href="{{ route('jobwarden.jobs', ['states' => [$k['state']]]) }}" wire:navigate>
                    <span class="kpi-label"><span class="sdot h-{{ $k['hue'] }}"></span>{{ $k['label'] }}</span>
                    <span class="kpi-value">{{ number_format($n) }}</span>
                    <span class="kpi-sub">{{ $k['sub'] }}</span>
                </a>
            @endforeach
        </div>

        <div style="display:grid;grid-template-columns:1.35fr 1fr;gap:16px;align-items:start">

            {{-- Needs attention --}}
            <section class="panel">
                <div class="panel-head">
                    <span class="sdot h-red"></span>
                    <span class="panel-title">Needs attention</span>
                    <span class="panel-note">orphaned · failed · stuck</span>
                    <a class="btn-link" style="margin-left:auto" href="{{ route('jobwarden.jobs') }}" wire:navigate>View all →</a>
                </div>
                <div>
                    @forelse ($attention as $job)
                        <div class="tbl-row click" style="grid-template-columns:96px minmax(0,1fr) 66px auto;padding-top:6px;padding-bottom:6px"
                             data-jw-href="{{ route('jobwarden.jobs.show', $job->id) }}">
                            @if ($job->getAttribute('is_stuck'))
                                <span class="badge h-amber"><span class="sdot pulse"></span>stuck</span>
                            @else
                                @include('jobwarden::partials.state-badge', ['state' => $job->state])
                            @endif
                            <div class="cell-main">
                                <div class="t">{{ class_basename($job->job_class) }}</div>
                                <div class="s">{{ $job->name ?? $job->id }}</div>
                            </div>
                            <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $job->created_at_ms])</span>
                            <span>
                                @if ($job->state === \JobWarden\States\JobState::Failed)
                                    <button type="button" class="btn sm btn-accent" wire:click="retry('{{ $job->id }}')">Retry</button>
                                @elseif (in_array($job->state, [\JobWarden\States\JobState::Orphaned, \JobWarden\States\JobState::Stopped], true))
                                    <button type="button" class="btn sm btn-purple" wire:click="restart('{{ $job->id }}')">Restart</button>
                                @else
                                    <a class="btn sm" href="{{ route('jobwarden.jobs.show', $job->id) }}" wire:navigate>Inspect</a>
                                @endif
                            </span>
                        </div>
                    @empty
                        <div class="empty" style="margin:14px">Nothing needs attention right now.</div>
                    @endforelse
                </div>
            </section>

            {{-- Right column --}}
            <div style="display:flex;flex-direction:column;gap:16px">
                <section class="panel panel-pad">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:11px">
                        <span class="panel-title">Active jobs by state</span>
                        <span class="panel-note">{{ number_format($inFlight) }} in flight</span>
                    </div>
                    @php($distTotal = max(1, $stateDist->sum('count')))
                    <div class="bar">
                        @foreach ($stateDist as $d)
                            @php($hue = match ($d['state']) { 'running', 'queued' => 'blue', 'retrying' => 'amber', 'orphaned' => 'purple', default => 'slate' })
                            <div class="fill-{{ $hue }}" style="width:{{ round($d['count'] / $distTotal * 100, 2) }}%" title="{{ $d['state'] }} · {{ $d['count'] }}"></div>
                        @endforeach
                    </div>
                    <div class="legend" style="margin-top:11px">
                        @forelse ($stateDist as $d)
                            @php($hue = match ($d['state']) { 'running', 'queued' => 'blue', 'retrying' => 'amber', 'orphaned' => 'purple', default => 'slate' })
                            <div><span class="sdot h-{{ $hue }}"></span><span>{{ $d['state'] }}</span><b>{{ number_format($d['count']) }}</b></div>
                        @empty
                            <div><span class="muted">nothing in flight</span></div>
                        @endforelse
                    </div>
                </section>

                <section class="panel panel-pad">
                    <div class="panel-title" style="margin-bottom:11px">Queue lanes</div>
                    @php($laneMax = max(1, (int) $byLane->max()))
                    <div style="display:flex;flex-direction:column;gap:9px">
                        @forelse ($byLane as $lane => $n)
                            <div class="lane-row">
                                <span class="name">{{ $lane }}</span>
                                <div class="bar thin"><div class="fill-blue" style="width:{{ round($n / $laneMax * 100, 2) }}%"></div></div>
                                <span class="n">{{ number_format($n) }}</span>
                            </div>
                        @empty
                            <span class="muted" style="font-size:11.5px">No jobs yet.</span>
                        @endforelse
                    </div>
                </section>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <section class="panel panel-pad">
                        <div class="panel-title" style="margin-bottom:10px">Workers</div>
                        <div style="display:flex;flex-direction:column;gap:8px">
                            @forelse ($workerRoles as $role => $n)
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span class="sdot h-green" style="width:6px;height:6px"></span>
                                    <span class="mono" style="font-size:11px;color:var(--fg-2);flex:1">{{ $role }}</span>
                                    <span class="mono" style="font-size:11.5px;font-weight:500">{{ $n }}</span>
                                </div>
                            @empty
                                <span class="muted" style="font-size:11.5px">No live workers.</span>
                            @endforelse
                        </div>
                    </section>
                    <section class="panel panel-pad">
                        <div class="panel-title" style="margin-bottom:10px">Batches</div>
                        <div style="display:flex;flex-direction:column;gap:8px">
                            @forelse ($batchStates as $state => $n)
                                @php($hue = match ($state) { 'succeeded' => 'green', 'failed' => 'red', 'running' => 'blue', 'partial' => 'amber', 'canceled', 'stopped' => 'gray', default => 'slate' })
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span class="sdot h-{{ $hue }}" style="width:6px;height:6px"></span>
                                    <span style="font-size:11px;color:var(--fg-2);flex:1">{{ $state }}</span>
                                    <span class="mono" style="font-size:11.5px;font-weight:500">{{ $n }}</span>
                                </div>
                            @empty
                                <span class="muted" style="font-size:11.5px">No batches yet.</span>
                            @endforelse
                        </div>
                    </section>
                </div>
            </div>
        </div>

        {{-- Recent activity --}}
        <section class="panel">
            <div class="panel-head">
                <span class="panel-title">Recent activity</span>
                <span class="panel-note">job_events · append-only audit</span>
            </div>
            <div>
                @forelse ($activity as $e)
                    <div class="tbl-row activity-grid">
                        <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $e->created_at_ms, 'mode' => 'time'])</span>
                        <span style="display:flex;align-items:center;gap:6px;min-width:0">
                            @php($hue = match ($e->to_state) { 'succeeded' => 'green', 'failed' => 'red', 'running', 'queued', 'dispatched' => 'blue', 'retrying' => 'amber', 'orphaned' => 'purple', 'canceled', 'stopped' => 'gray', default => 'slate' })
                            <span class="sdot h-{{ $hue }}" style="width:6px;height:6px"></span>
                            <span class="cell-dim">{{ $e->from_state ?? '·' }} → {{ $e->to_state }}</span>
                        </span>
                        <span class="cell-txt" style="color:var(--fg)">
                            @if ($e->job)
                                <a href="{{ route('jobwarden.jobs.show', $e->job_id) }}" wire:navigate>{{ class_basename($e->job->job_class) }}{{ $e->job->name ? ' · '.$e->job->name : '' }}</a>
                            @else
                                <span class="mono">{{ $e->job_id }}</span>
                            @endif
                            @if ($e->reason)<span class="muted"> — {{ $e->reason }}</span>@endif
                        </span>
                        <span class="cell-dim" style="font-size:10px">{{ $e->actor_type->value }}{{ $e->actor_id ? ' · '.$e->actor_id : '' }}</span>
                    </div>
                @empty
                    <div class="empty" style="margin:14px">No events yet — dispatch something.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
