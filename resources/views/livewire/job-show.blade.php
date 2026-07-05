@php
    use JobWarden\States\JobState;

    $fmtMs = function ($ms): string {
        if ($ms === null) return '—';
        if ($ms >= 60000) return floor($ms / 60000).'m '.round(($ms % 60000) / 1000).'s';
        if ($ms >= 1000) return round($ms / 1000, 1).'s';
        return $ms.'ms';
    };

    $actionButtons = [
        'retry' => ['label' => 'Retry', 'class' => 'btn-accent', 'confirm' => null],
        'restart' => ['label' => 'Restart', 'class' => 'btn-purple', 'confirm' => null],
        'cancel' => ['label' => 'Cancel', 'class' => '', 'confirm' => 'Cancel this job?'],
        'stop' => ['label' => 'Stop', 'class' => 'btn-red', 'confirm' => 'Stop this job?'],
    ];
@endphp
<div class="view" wire:poll.{{ config('jobwarden.dashboard.poll', '10s') }}>
    <div class="detail-head">
        <a class="backlink" href="{{ route('jobwarden.jobs') }}" wire:navigate>← Jobs</a>
        <div class="detail-title-row">
            <div class="grow">
                <div class="detail-title">
                    <span class="name">{{ class_basename($job->job_class) }}</span>
                    @include('jobwarden::partials.state-badge', ['state' => $job->state])
                    @if ($job->cancel_requested && ! in_array($job->state, [JobState::Succeeded, JobState::Failed, JobState::Canceled, JobState::Stopped], true))
                        <span class="badge h-amber">{{ $job->cancel_mode ?? 'cancel' }} requested</span>
                    @endif
                </div>
                <div class="detail-sub">{{ $job->job_class }} · {{ $job->id }}</div>
            </div>
            <div class="detail-actions">
                @foreach (\JobWarden\Http\Livewire\JobShow::allowedActions($job) as $action)
                    @php($btn = $actionButtons[$action])
                    <button type="button" class="btn {{ $btn['class'] }}" wire:click="{{ $action }}"
                        @if ($btn['confirm']) wire:confirm="{{ $btn['confirm'] }}" @endif>{{ $btn['label'] }}</button>
                @endforeach
            </div>
        </div>

        {{-- meta grid --}}
        <div class="meta-grid">
            <div class="meta-cell"><div class="k">Job id</div><div class="v" title="{{ $job->id }}">{{ $job->id }}</div></div>
            <div class="meta-cell"><div class="k">Lane</div><div class="v">@include('jobwarden::partials.lane-badge', ['lane' => $job->lane])</div></div>
            <div class="meta-cell"><div class="k">Priority</div><div class="v">{{ $job->priority }}</div></div>
            <div class="meta-cell"><div class="k">Idempotent</div><div class="v {{ $job->idempotent ? 'text-green' : 'text-amber' }}">{{ $job->idempotent ? 'true' : 'false' }}</div></div>
            <div class="meta-cell"><div class="k">Attempts</div><div class="v">{{ $job->attempt_count }}/{{ $job->max_attempts }}</div></div>
            <div class="meta-cell"><div class="k">Source</div><div class="v">
                @if ($job->schedule)
                    <a href="{{ route('jobwarden.schedules.show', $job->schedule_id) }}" wire:navigate>schedule: {{ $job->schedule->name }}</a>
                @elseif ($job->batch)
                    <a href="{{ route('jobwarden.batches.show', $job->batch_id) }}" wire:navigate>batch: {{ $job->batch->name ?? \Illuminate\Support\Str::substr($job->batch_id, 0, 8) }}</a>
                @else
                    {{ $job->created_by ?? 'api' }}
                @endif
            </div></div>
            <div class="meta-cell"><div class="k">Last host</div><div class="v">{{ $lastAttempt?->hostname ?? '—' }}</div></div>
            <div class="meta-cell"><div class="k">Duration</div><div class="v">{{ $fmtMs($lastAttempt?->duration_ms) }}</div></div>
        </div>

        {{-- params + tags (first-class) --}}
        <div class="pt-grid">
            <div class="kv-panel">
                <div class="kv-head"><b>Params</b><i>bound to the constructor by name · may hold PII</i></div>
                @if (! empty($job->params))
                    <div class="kv-body">
                        @foreach ($job->params as $k => $v)
                            <div class="kv-row">
                                <span class="k">{{ $k }}</span>
                                @if (is_string($v))
                                    <span class="v v-str">"{{ $v }}"</span>
                                @elseif (is_bool($v))
                                    <span class="v v-bool">{{ $v ? 'true' : 'false' }}</span>
                                @elseif (is_numeric($v))
                                    <span class="v v-num">{{ $v }}</span>
                                @elseif ($v === null)
                                    <span class="v v-null">null</span>
                                @else
                                    <span class="v">{{ json_encode($v, JSON_UNESCAPED_SLASHES) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="kv-empty">No params.</div>
                @endif
            </div>
            <div class="kv-panel">
                <div class="kv-head"><b>Tags</b><i>indexed · searchable</i></div>
                <div style="padding:12px">
                    @if ($job->tags->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:7px">
                            @foreach ($job->tags as $tag)
                                <a class="tagchip" href="{{ route('jobwarden.jobs', ['q' => $tag->name.':'.$tag->value]) }}" wire:navigate><i>{{ $tag->name }}:</i>{{ $tag->value }}</a>
                            @endforeach
                        </div>
                    @else
                        <span style="font-size:11.5px;color:var(--fg-3)">No tags on this job.</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- last_error --}}
        @if (! empty($job->last_error))
            <div class="errpanel">
                <div class="head">
                    <span class="tag">last_error</span>
                    <span class="cls">{{ $job->last_error['class'] ?? '' }}</span>
                </div>
                <div class="msg">{{ $job->last_error['message'] ?? '' }}</div>
                @if (! empty($job->last_error['file']))
                    <div class="loc">{{ $job->last_error['file'] }}</div>
                @endif
                @if (! empty($job->last_error['trace']))
                    <div class="trace">@foreach ((array) $job->last_error['trace'] as $line)
{{ is_string($line) ? $line : json_encode($line) }}
@endforeach</div>
                @endif
            </div>
        @endif

        {{-- tabs --}}
        <div class="tabs">
            @foreach (['logs' => 'Logs', 'attempts' => 'Attempts ('.$job->attempts->count().')', 'timeline' => 'Timeline', 'result' => 'Result'] as $key => $label)
                <button type="button" class="tab {{ $tab === $key ? 'on' : '' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="tab-body">
        @if ($tab === 'logs')
            <livewire:jobwarden.job-log-tail :job-id="$job->id" :key="'tail-'.$job->id" />
        @elseif ($tab === 'attempts')
            <div style="border:1px solid var(--border);border-radius:9px;overflow:hidden">
                <div class="tbl-head attempts-grid" style="border-radius:9px 9px 0 0">
                    <span>#</span><span>State</span><span>Host</span><span>Duration</span><span>Exit</span><span>At</span>
                </div>
                @forelse ($job->attempts as $a)
                    <div class="tbl-row attempts-grid" style="padding-top:5px;padding-bottom:5px">
                        <span class="mono" style="font-size:12px">{{ $a->attempt_number }}</span>
                        @include('jobwarden::partials.state-badge', ['state' => $a->state, 'pulse' => false])
                        <span class="cell-mono">{{ $a->hostname ?? $a->host_id ?? '—' }}</span>
                        <span class="cell-mono">{{ $fmtMs($a->duration_ms) }}</span>
                        <span class="cell-mono">{{ $a->exit_code ?? '—' }}{{ $a->term_signal ? ' · sig '.$a->term_signal : '' }}</span>
                        <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $a->started_at_ms ?? $a->created_at_ms])</span>
                    </div>
                @empty
                    <div class="empty" style="margin:14px">No attempts yet — the job hasn't been claimed.</div>
                @endforelse
            </div>
        @elseif ($tab === 'timeline')
            <div class="tl">
                @forelse ($job->events as $e)
                    @php($hue = match ($e->to_state) { 'succeeded' => 'green', 'failed' => 'red', 'running', 'queued', 'dispatched' => 'blue', 'retrying' => 'amber', 'orphaned' => 'purple', 'canceled', 'stopped' => 'gray', default => 'slate' })
                    <div class="tl-item">
                        <div class="tl-rail"><span class="sdot h-{{ $hue }}"></span><span class="line"></span></div>
                        <div>
                            <div class="tl-head">
                                <span class="tl-arrow">{{ $e->from_state ?? '·' }} → {{ $e->to_state }}</span>
                                <span class="tl-time">@include('jobwarden::partials.time', ['ms' => $e->created_at_ms])</span>
                                <span class="tl-actor">{{ $e->actor_type->value }}{{ $e->actor_id ? ' · '.$e->actor_id : '' }}</span>
                            </div>
                            @if ($e->reason)<div class="tl-reason">{{ $e->reason }}</div>@endif
                        </div>
                    </div>
                @empty
                    <div class="empty">No events recorded.</div>
                @endforelse
            </div>
        @elseif ($tab === 'result')
            @if ($job->state === JobState::Succeeded && $job->result !== null)
                <div class="jsonbox">{{ json_encode($job->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
            @else
                <div class="empty">No result to show.</div>
            @endif
            <div class="footnote">result is success-only — written atomically with the succeeded transition (≤ 64 KiB). null if the handler never called JobContext::result(); failures carry their story in last_error.</div>
        @endif
    </div>
</div>
