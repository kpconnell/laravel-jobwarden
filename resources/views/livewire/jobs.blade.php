<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <h1>Jobs</h1>

    <div class="toolbar">
        <input type="text" wire:model.live.debounce.400ms="q" placeholder="search class…" style="min-width:200px">
        <select wire:model.live="state">
            <option value="">any state</option>
            @foreach (['pending','queued','running','retrying','orphaned','succeeded','failed','canceled','stopped'] as $s)
                <option value="{{ $s }}">{{ $s }}</option>
            @endforeach
        </select>
        <select wire:model.live="lane">
            <option value="">any lane</option>
            <option value="default">default</option>
            <option value="scheduled">scheduled</option>
        </select>
        @if ($state || $lane || $q || $batch_id)
            <button class="btn" wire:click="clear">clear</button>
        @endif
        <span class="muted">{{ $jobs->total() }} job(s)</span>
    </div>

    <table>
        <thead><tr><th>Job</th><th>Name</th><th>Class</th><th>Lane</th><th>State</th><th>Att</th><th>Created</th></tr></thead>
        <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td><a href="{{ route('jobwarden.jobs.show', $job->id) }}"><code>{{ \Illuminate\Support\Str::substr($job->id, 0, 8) }}</code></a></td>
                    <td class="muted">{{ $job->name ?? '—' }}</td>
                    <td>{{ class_basename($job->job_class) }}</td>
                    <td class="muted">{{ $job->lane }}</td>
                    <td><span class="badge state-{{ $job->state->value }}">{{ $job->state->value }}</span></td>
                    <td class="muted">{{ $job->attempt_count }}/{{ $job->max_attempts }}</td>
                    <td class="muted">{{ optional($job->created_at)->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">no jobs match</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pager">{{ $jobs->links() }}</div>
</div>
