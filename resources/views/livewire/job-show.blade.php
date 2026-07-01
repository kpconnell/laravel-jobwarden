<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <p><a href="{{ route('jobwarden.jobs') }}" class="muted">← jobs</a></p>
    <h1>{{ class_basename($job->job_class) }} <span class="badge state-{{ $job->state->value }}">{{ $job->state->value }}</span></h1>

    @if ($flash)<div class="flash">{{ $flash }}</div>@endif

    @php($active = in_array($job->state->value, ['pending','queued','running','retrying','orphaned']))
    @php($failed = in_array($job->state->value, ['failed','orphaned']))
    @php($terminal = in_array($job->state->value, ['succeeded','failed','canceled','stopped']))
    <div class="btn-row" style="margin-bottom:16px">
        @if ($active)
            <button class="btn danger" wire:click="cancel" wire:confirm="Cancel this job?">Cancel</button>
            <button class="btn danger" wire:click="stop" wire:confirm="Stop this job?">Stop</button>
        @endif
        @if ($failed)
            <button class="btn" wire:click="retry">Retry</button>
        @endif
        @if ($terminal)
            <button class="btn" wire:click="restart" wire:confirm="Restart a fresh run?">Restart</button>
        @endif
    </div>

    <div class="kv">
        <div class="k">id</div><div><code>{{ $job->id }}</code></div>
        <div class="k">lane</div><div>{{ $job->lane }}</div>
        <div class="k">idempotent</div><div>{{ $job->idempotent ? 'yes' : 'no' }}</div>
        <div class="k">attempts</div><div>{{ $job->attempt_count }} / {{ $job->max_attempts }}</div>
        @if ($job->batch_id)<div class="k">batch</div><div><a href="{{ route('jobwarden.batches') }}">{{ \Illuminate\Support\Str::substr($job->batch_id,0,8) }}</a></div>@endif
        @if ($job->schedule_id)<div class="k">schedule</div><div><a href="{{ route('jobwarden.schedules') }}">{{ \Illuminate\Support\Str::substr($job->schedule_id,0,8) }}</a></div>@endif
        <div class="k">started</div><div class="muted">{{ optional($job->started_at)->diffForHumans() ?? '—' }}</div>
        <div class="k">finished</div><div class="muted">{{ optional($job->finished_at)->diffForHumans() ?? '—' }}</div>
    </div>

    @if ($job->params)
        <h2>Params</h2>
        <pre class="json">{{ json_encode($job->params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
    @endif
    @if ($job->last_error)
        <h2>Last error</h2>
        @php($err = $job->last_error)
        @if (is_array($err) && isset($err['class']))
            <div class="err">
                <div class="err-class">{{ $err['class'] }}</div>
                @if (!empty($err['message']))<div class="err-msg">{{ $err['message'] }}</div>@endif
                @if (!empty($err['file']))<div class="err-file muted">at <code>{{ $err['file'] }}</code></div>@endif
                @if (!empty($err['trace']))<pre class="json trace">{{ $err['trace'] }}</pre>@endif
            </div>
        @else
            <pre class="json">{{ json_encode($err, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    @endif

    <h2>Attempts</h2>
    <table>
        <thead><tr><th>#</th><th>State</th><th>Host</th><th>Child PID</th><th>Exit</th><th>Signal</th><th>Duration</th></tr></thead>
        <tbody>
            @forelse ($job->attempts as $a)
                <tr>
                    <td>{{ $a->attempt_number }}</td>
                    <td><span class="badge state-{{ $a->state->value ?? $a->state }}">{{ $a->state->value ?? $a->state }}</span></td>
                    <td class="muted"><code>{{ \Illuminate\Support\Str::substr((string) $a->host_id, 0, 8) ?: '—' }}</code></td>
                    <td class="muted">{{ $a->child_pid ?? '—' }}</td>
                    <td class="muted">{{ $a->exit_code ?? '—' }}</td>
                    <td class="muted">{{ $a->term_signal ?? '—' }}</td>
                    <td class="muted">{{ $a->duration_ms ? $a->duration_ms.'ms' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">no attempts</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sec-head">
        <h2>Logs</h2>
        @if ($logs->isNotEmpty())
            <button class="btn" wire:click="openLogs">View all</button>
        @endif
    </div>
    <div class="logs">
        @forelse ($logs as $l)
            <div class="ln lvl-{{ $l->level }}"><span class="muted">{{ optional($l->ts)->format('H:i:s') }}</span> {{ $l->step ? '['.$l->step.'] ' : '' }}{{ $l->body }}</div>
        @empty
            <div class="muted">no logs</div>
        @endforelse
    </div>

    <h2>Events</h2>
    <table>
        <thead><tr><th>Level</th><th>Transition</th><th>Actor</th><th>Reason</th><th>At</th></tr></thead>
        <tbody>
            @foreach ($job->events as $e)
                <tr>
                    <td class="muted">{{ $e->level }}</td>
                    <td><span class="muted">{{ $e->from_state ?? '∅' }}</span> → <span class="badge state-{{ $e->to_state }}">{{ $e->to_state }}</span></td>
                    <td class="muted">{{ $e->actor_type }}</td>
                    <td class="muted">{{ $e->reason }}</td>
                    <td class="muted">{{ optional($e->created_at)->format('H:i:s') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($showAllLogs)
        <div class="modal-overlay" wire:click.self="closeLogs" wire:keydown.escape.window="closeLogs">
            <div class="modal">
                <div class="modal-head">
                    <h2>Logs — {{ class_basename($job->job_class) }}</h2>
                    <button class="btn" wire:click="closeLogs">Close</button>
                </div>
                @if ($allLogsTruncated)
                    <div class="muted modal-note">Showing the first {{ number_format($dialogLogCap) }} lines. Run <code>jobwarden:logs {{ \Illuminate\Support\Str::substr($job->id, 0, 8) }} --export</code> for the complete bundle.</div>
                @endif
                <div class="logs modal-logs">
                    @forelse ($allLogs as $l)
                        <div class="ln lvl-{{ $l->level }}"><span class="muted">{{ optional($l->ts)->format('H:i:s') }}</span> {{ $l->step ? '['.$l->step.'] ' : '' }}{{ $l->body }}</div>
                    @empty
                        <div class="muted">no logs</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
