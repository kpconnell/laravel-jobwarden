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

    <div class="tbl-head workers-grid" style="padding:0 16px">
        <span>Role</span><span>Host</span><span>State</span><span>PID</span><span>Load</span><span>Versions</span><span>Heartbeat</span>
    </div>
    <div class="tbl-scroll">
        @forelse ($workers as $w)
            @php($pct = $w->capacity ? min(100, round($w->current_load / max(1, $w->capacity) * 100)) : null)
            <div class="tbl-row workers-grid" style="padding:0 16px;min-height:44px" wire:key="worker-{{ $w->id }}">
                <span class="cell-mono" style="font-weight:500;color:var(--fg)">{{ $w->role }}</span>
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
