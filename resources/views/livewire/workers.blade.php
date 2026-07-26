<div class="view" wire:poll.5s>
    <div class="toolbar">
        @forelse ($roleCounts as $role => $n)
            <div class="sum-chip">
                <span class="sdot h-green" style="width:6px;height:6px"></span>
                <span>{{ $role }}</span><b>{{ $n }}</b>
            </div>
        @empty
            <span class="info">no live workers</span>
        @endforelse
        @if ($deadSupervisors > 0)
            <div class="sum-chip bad">
                <span class="sdot h-red" style="width:6px;height:6px"></span>
                <span>dead supervisors</span><b>{{ $deadSupervisors }}</b>
            </div>
        @endif
        <div class="right">
            <button type="button" class="btn sm {{ $all ? 'btn-accent' : '' }}" wire:click="toggleAll">{{ $all ? 'Hide' : 'Show' }} stopped/dead</button>
        </div>
    </div>

    {{-- Lane coverage. A lane with queued work and no live supervisor is the one
         fleet failure nothing else reports: recovery recovers work, never processes,
         so a supervisor that dies and is never restarted leaves a consistent database
         and a lane that has quietly stopped running. --}}
    @if (count($laneCoverage) > 0)
        @php($uncovered = collect($laneCoverage)->where('supervisors', 0)->where('queued', '>', 0))
        <div class="toolbar">
            <span class="info">lanes</span>
            @foreach ($laneCoverage as $l)
                <div class="sum-chip {{ $l['supervisors'] === 0 && $l['queued'] > 0 ? 'bad' : '' }}">
                    <span class="sdot {{ $l['supervisors'] > 0 ? 'h-green' : ($l['queued'] > 0 ? 'h-red' : 'h-amber') }}" style="width:6px;height:6px"></span>
                    <span>{{ $l['lane'] }}</span><b>{{ $l['supervisors'] }}</b>
                    <span>cap {{ $l['capacity'] }}</span>
                    @if ($l['queued'] > 0)
                        <span>· {{ number_format($l['queued']) }} queued</span>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($uncovered->isNotEmpty())
            <div class="toolbar" style="background:var(--h-red-bg);border-bottom-color:var(--h-red-dot)">
                <span class="info" style="color:var(--h-red-fg)">
                    no live supervisor for {{ $uncovered->pluck('lane')->join(', ') }} —
                    {{ number_format((int) $uncovered->sum('queued')) }} job(s) will sit queued until one is started.
                    JobWarden never starts a process; check the launcher for that lane.
                </span>
            </div>
        @endif
    @endif

    <div class="tbl-head workers-grid" style="padding:0 16px">
        <span>Role</span><span>Host</span><span>State</span><span>PID</span><span>Load</span><span>Versions</span><span>Heartbeat</span>
    </div>
    <div class="tbl-scroll">
        @forelse ($workers as $w)
            @php($pct = $w->capacity ? min(100, round($w->current_load / max(1, $w->capacity) * 100)) : null)
            <div class="tbl-row workers-grid" style="padding:0 16px;min-height:44px" wire:key="worker-{{ $w->id }}">
                <div class="cell-main">
                    <div class="t mono" style="font-weight:500;font-size:12px">{{ $w->role }}</div>
                    @if ($w->role === 'supervisor')
                        <div class="s">lane {{ $w->meta['lane'] ?? 'default' }}</div>
                    @endif
                </div>
                <div class="cell-main">
                    <div class="t mono" style="font-weight:400;font-size:12px">{{ $w->hostname ?? $w->host_id }}</div>
                    <div class="s">inc {{ $w->incarnation }} · {{ \Illuminate\Support\Str::substr((string) $w->id, 0, 8) }}</div>
                </div>
                @include('jobwarden::partials.state-badge', ['state' => $w->state])
                <span class="cell-mono">{{ $w->pid ?? '—' }}</span>
                <div class="loadbar">
                    <div class="bar">
                        @if ($pct !== null)
                            <div class="{{ $pct > 90 ? 'fill-red' : 'fill-blue' }}" style="width:{{ $pct }}%"></div>
                        @endif
                    </div>
                    <span class="cap">{{ $w->current_load }}/{{ $w->capacity ?? '∞' }}</span>
                </div>
                <span class="cell-dim">{{ $w->app_version ?? '—' }} · {{ $w->php_version ?? '—' }}</span>
                <span class="cell-mono">@include('jobwarden::partials.time', ['ms' => $w->heartbeat_at_ms])</span>
            </div>
        @empty
            <div class="empty">
                @if ($all)
                    No workers have ever registered — is the fleet running?
                @else
                    No live workers. <button type="button" class="btn-link" wire:click="toggleAll">Show stopped/dead</button>
                @endif
            </div>
        @endforelse
    </div>
</div>
