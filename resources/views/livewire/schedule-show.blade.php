<div class="view">
    <div class="detail-head pad-b">
        <a class="backlink" href="{{ route('jobwarden.schedules') }}" wire:navigate>← Schedules</a>
        <div class="detail-title-row">
            <div class="grow">
                <div class="detail-title">
                    <span class="name">{{ $schedule->name }}</span>
                    <span class="badge h-{{ $schedule->enabled ? 'green' : 'gray' }}"><span class="sdot"></span>{{ $schedule->enabled ? 'enabled' : 'disabled' }}</span>
                    @include('jobwarden::partials.lane-badge', ['lane' => 'scheduled'])
                </div>
                <div class="detail-sub">
                    @if ($schedule->job_class === \JobWarden\Jobs\RunArtisanCommand::class)
                        artisan {{ data_get($schedule->params, 'command') }}
                    @else
                        {{ $schedule->job_class }}
                    @endif
                </div>
            </div>
            <div class="detail-actions">
                <button type="button" class="btn btn-accent" wire:click="runNow">Run now</button>
                <button type="button" class="btn" wire:click="toggle">{{ $schedule->enabled ? 'Disable' : 'Enable' }}</button>
                <button type="button" class="btn btn-red" wire:click="deleteSchedule" wire:confirm="Delete schedule {{ $schedule->name }}? Its run history goes with it.">Delete</button>
            </div>
        </div>

        <div class="meta-grid" style="margin-bottom:0">
            <div class="meta-cell"><div class="k">Cron</div><div class="v">{{ $schedule->cron_expression ?? 'one-time' }}</div></div>
            <div class="meta-cell"><div class="k">Timezone</div><div class="v">{{ $schedule->timezone }}</div></div>
            <div class="meta-cell"><div class="k">Next due</div><div class="v">@include('jobwarden::partials.time', ['ms' => $schedule->next_due_at_ms])</div></div>
            <div class="meta-cell"><div class="k">Last enqueued for</div><div class="v">@include('jobwarden::partials.time', ['ms' => $schedule->last_enqueued_for_ms])</div></div>
            <div class="meta-cell"><div class="k">Idempotent</div><div class="v {{ $schedule->idempotent ? 'text-green' : 'text-amber' }}">{{ $schedule->idempotent ? 'true' : 'false' }}</div></div>
            <div class="meta-cell"><div class="k">Max attempts</div><div class="v">{{ $schedule->max_attempts ?? ($schedule->idempotent ? '3 (derived)' : '1 (derived)') }}</div></div>
            <div class="meta-cell"><div class="k">Missed policy</div><div class="v">{{ $schedule->missed_policy }}</div></div>
            <div class="meta-cell"><div class="k">Overlap policy</div><div class="v">{{ $schedule->overlap_policy }}</div></div>
        </div>
    </div>

    <div class="tab-body">
        <div style="font-size:12px;font-weight:600;color:var(--fg-2);margin-bottom:10px">
            Recent runs <span class="panel-note" style="font-weight:400">· schedule_runs · last 25 occurrences</span>
        </div>
        <div style="border:1px solid var(--border);border-radius:9px;overflow:hidden">
            <div class="tbl-head runs-grid">
                <span>Occurrence</span><span>Action</span><span>Reason</span><span>Job</span>
            </div>
            @forelse ($runs as $r)
                <div class="tbl-row runs-grid" style="padding-top:5px;padding-bottom:5px" wire:key="run-{{ $r->id }}">
                    <span class="cell-mono">@include('jobwarden::partials.time', ['ms' => $r->occurrence_time_ms])</span>
                    <span style="display:flex;align-items:center;gap:6px">
                        @php($hue = match ($r->action) { 'enqueued' => 'green', 'skipped' => 'gray', 'coalesced', 'overlapped' => 'amber', default => 'slate' })
                        <span class="sdot h-{{ $hue }}" style="width:6px;height:6px"></span>
                        <span class="cell-mono" style="color:var(--fg)">{{ $r->action }}</span>
                    </span>
                    <span class="cell-txt">{{ $r->reason ?? '—' }}</span>
                    <span class="cell-dim">
                        @if ($r->job_id && $r->job)
                            <a href="{{ route('jobwarden.jobs.show', $r->job_id) }}" wire:navigate>{{ \Illuminate\Support\Str::substr($r->job_id, 0, 13) }}… · {{ $r->job->state->value }}</a>
                        @else
                            —
                        @endif
                    </span>
                </div>
            @empty
                <div class="empty" style="margin:14px">No runs recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>
