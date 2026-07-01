<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <h1>Workers</h1>
    <div class="toolbar">
        <label class="muted"><input type="checkbox" wire:model.live="all"> show stopped/dead</label>
        <span class="muted">{{ $workers->count() }} process(es)</span>
    </div>
    <table>
        <thead><tr><th>id</th><th>role</th><th>state</th><th>host</th><th>pid</th><th>lane</th><th>load</th><th>heartbeat</th></tr></thead>
        <tbody>
            @forelse ($workers as $w)
                <tr>
                    <td><code>{{ \Illuminate\Support\Str::substr((string) $w->id, 0, 8) }}</code></td>
                    <td>{{ $w->role }}</td>
                    <td><span class="badge state-{{ $w->state === 'active' ? 'running' : ($w->state === 'dead' ? 'failed' : 'queued') }}">{{ $w->state }}</span></td>
                    <td class="muted"><code>{{ \Illuminate\Support\Str::substr((string) $w->host_id, 0, 8) }}</code></td>
                    <td class="muted">{{ $w->pid ?? '—' }}</td>
                    <td class="muted">{{ data_get($w->meta, 'lane', '—') }}</td>
                    <td class="muted">{{ $w->current_load }}/{{ $w->capacity ?? '∞' }}</td>
                    <td class="muted">{{ $w->heartbeat_at ? (int) round(abs(now()->diffInSeconds($w->heartbeat_at))).'s ago' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">no workers</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
