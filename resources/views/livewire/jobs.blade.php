<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <h1>Jobs</h1>

    <div class="toolbar">
        <input type="text" wire:model.live.debounce.400ms="q" placeholder="class, name, or tag:value…" style="min-width:260px"
               title="tokens AND together — bare words match class/name; name:value matches a tag; trailing * = prefix (e.g. store:AMAZ date:2025-01*)">
        <select wire:model.live="state">
            <option value="">any state</option>
            @foreach (['pending','queued','running','retrying','orphaned','succeeded','failed','canceled','stopped'] as $s)
                <option value="{{ $s }}">{{ $s }}</option>
            @endforeach
        </select>
        <select wire:model.live="lane">
            <option value="">any lane</option>
            @foreach ($lanes as $l)
                <option value="{{ $l }}">{{ $l }}</option>
            @endforeach
        </select>
        <select wire:model.live="job_class">
            <option value="">any class</option>
            @foreach ($classes as $c)
                <option value="{{ $c }}">{{ class_basename($c) }}</option>
            @endforeach
        </select>
        @if ($state || $lane || $job_class || $q || $batch_id)
            <button class="btn" wire:click="clear">clear</button>
        @endif
        <span class="muted">{{ $jobs->total() }} job(s)</span>
    </div>

    <table>
        <thead><tr><th>Job</th><th>Name</th><th>Class</th><th>Lane</th><th>State</th><th>Att</th><th>Created</th><th>Started</th></tr></thead>
        <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td><a href="{{ route('jobwarden.jobs.show', $job->id) }}"><code>{{ \Illuminate\Support\Str::substr($job->id, -8) }}</code></a></td>
                    <td class="muted">{{ $job->name ?? '—' }}</td>
                    <td>{{ class_basename($job->job_class) }}</td>
                    <td class="muted">{{ $job->lane }}</td>
                    <td><span class="badge state-{{ $job->state->value }}">{{ $job->state->value }}</span></td>
                    <td class="muted">{{ $job->attempt_count }}/{{ $job->max_attempts }}</td>
                    <td class="muted">@include('jobwarden::partials.time', ['ms' => $job->created_at_ms, 'mode' => 'relative'])</td>
                    <td class="muted">@include('jobwarden::partials.time', ['ms' => $job->started_at_ms, 'mode' => 'relative'])</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">no jobs match</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pager">{{ $jobs->links() }}</div>
</div>
