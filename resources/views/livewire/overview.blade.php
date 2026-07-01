<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <h1>Overview</h1>

    @php($order = ['running','queued','retrying','orphaned','succeeded','failed','canceled','stopped','pending'])
    <div class="grid">
        @foreach ($order as $s)
            @if (($states[$s] ?? 0) > 0 || in_array($s, ['running','queued','failed','orphaned']))
                <div class="card">
                    <div class="n">{{ $states[$s] ?? 0 }}</div>
                    <div class="l"><span class="badge state-{{ $s }}">{{ $s }}</span></div>
                </div>
            @endif
        @endforeach
    </div>

    @if ($attention > 0)
        <p class="muted" style="margin-top:14px">⚠ {{ $attention }} job(s) need attention (failed / orphaned) — see <a href="{{ route('jobwarden.jobs') }}?state=orphaned">Jobs</a>.</p>
    @endif

    <h2>Lanes</h2>
    <div class="grid">
        @foreach ($byLane as $lane => $c)
            <div class="card"><div class="n">{{ $c }}</div><div class="l">{{ $lane }} lane</div></div>
        @endforeach
    </div>

    <h2>Fleet</h2>
    <div class="grid">
        @forelse ($workers as $role => $c)
            <div class="card"><div class="n">{{ $c }}</div><div class="l">{{ $role }}</div></div>
        @empty
            <div class="card"><div class="n">0</div><div class="l muted">no live workers</div></div>
        @endforelse
        <div class="card"><div class="n">{{ $batches }}</div><div class="l">running batches</div></div>
        <div class="card"><div class="n">{{ $schedules }}</div><div class="l">enabled schedules</div></div>
    </div>

    <h2>Recent jobs</h2>
    <table>
        <thead><tr><th>Job</th><th>Class</th><th>Lane</th><th>State</th><th>Created</th></tr></thead>
        <tbody>
            @forelse ($recent as $job)
                <tr>
                    <td><a href="{{ route('jobwarden.jobs.show', $job->id) }}"><code>{{ \Illuminate\Support\Str::substr($job->id, 0, 8) }}</code></a></td>
                    <td>{{ class_basename($job->job_class) }}</td>
                    <td class="muted">{{ $job->lane }}</td>
                    <td><span class="badge state-{{ $job->state->value }}">{{ $job->state->value }}</span></td>
                    <td class="muted">{{ optional($job->created_at)->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">no jobs yet</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
