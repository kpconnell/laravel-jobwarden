{{--
    "Why hasn't this job started yet?" — the mirror of the errpanel, which
    answers why a job stopped. Rendered only for pending/queued/retrying; the
    gates and their order come from JobWarden\Health\WaitAnalysis, which tracks
    the admission path exactly.

    @param array $waiting  WaitAnalysis::for($job)
--}}
@php
    $w = $waiting;
    $lane = $w['lane'];
    $pending = $w['state'] === 'pending';
    $queued = $w['state'] === 'queued';

    // Gate marker: green = satisfied, hued = the thing holding the job,
    // slate = context rather than a gate for this state.
    $dot = fn (?bool $ok): string => $ok === null ? 'slate' : ($ok ? 'green' : $w['headline']['tone']);

    $edge = fn (string $c): string => $c === 'on_completion' ? 'needs: any terminal' : 'needs: succeeded';
    $orphanBlocked = collect($w['job_deps']['items'])->contains(fn (array $d): bool => $d['state'] === 'orphaned');
@endphp
<div class="waitpanel h-{{ $w['headline']['tone'] }}">
    <div class="head">
        <span class="tag">waiting on</span>
        <span class="why">
            {{ $w['headline']['text'] }}
            @if ($w['headline']['at_ms'] !== null)@include('jobwarden::partials.time', ['ms' => $w['headline']['at_ms']])@endif
        </span>
    </div>

    <div class="rows">
        {{-- 1. availability --}}
        <div class="wrow">
            <span class="sdot h-{{ $dot($w['due']['ok']) }}"></span>
            <span class="k">available_at</span>
            <span class="v">
                @if (! $w['due']['ok'])
                    not reached — @include('jobwarden::partials.time', ['ms' => $w['due']['at_ms']])
                @elseif ($w['due']['at_ms'] === null)
                    <span class="muted">unset — no delay</span>
                @else
                    reached @include('jobwarden::partials.time', ['ms' => $w['due']['at_ms']])
                @endif
            </span>
        </div>

        {{-- 2. DAG edges (only gate `pending`; retries are deliberately not dep-guarded) --}}
        @if ($pending)
            <div class="wrow">
                <span class="sdot h-{{ $dot($w['job_deps']['items'] === []) }}"></span>
                <span class="k">dependencies</span>
                <span class="v">
                    @if ($w['job_deps']['items'] === [])
                        <span class="muted">no unmet edges</span>
                    @else
                        @foreach ($w['job_deps']['items'] as $d)
                            <span class="dep">
                                @include('jobwarden::partials.state-badge', ['state' => $d['state'], 'pulse' => false])
                                <a class="cls" href="{{ route('jobwarden.jobs.show', $d['id']) }}" wire:navigate>{{ $d['label'] }}</a>
                                <i class="cond">{{ $edge($d['edge_condition']) }}</i>
                            </span>
                        @endforeach
                        @if ($w['job_deps']['more'])
                            <span class="dep muted">… and more unmet edges</span>
                        @endif
                    @endif
                </span>
            </div>
        @endif

        {{-- 3. cross-batch edges — only shown when one is actually blocking --}}
        @if ($pending && $w['batch_deps']['items'] !== [])
            <div class="wrow">
                <span class="sdot h-{{ $w['headline']['tone'] }}"></span>
                <span class="k">upstream batches</span>
                <span class="v">
                    @foreach ($w['batch_deps']['items'] as $b)
                        <span class="dep">
                            @include('jobwarden::partials.state-badge', ['state' => $b['state'], 'pulse' => false])
                            <a class="cls" href="{{ route('jobwarden.batches.show', $b['id']) }}" wire:navigate>{{ $b['label'] }}</a>
                            <i class="cond">{{ $b['edge_condition'] === 'on_completion' ? 'needs: ended + quiescent' : 'needs: succeeded' }}</i>
                            @if ($b['in_flight'] > 0)<i class="cond">· {{ $b['in_flight'] }} still in flight</i>@endif
                        </span>
                    @endforeach
                    @if ($w['batch_deps']['more'])
                        <span class="dep muted">… and more unmet edges</span>
                    @endif
                </span>
            </div>
        @endif

        {{-- 4. the admit pass itself: not lane-scoped, so it is a fleet-wide question --}}
        @if (! $queued)
            <div class="wrow">
                <span class="sdot h-{{ $dot($lane['fleet'] > 0) }}"></span>
                <span class="k">admit pass</span>
                <span class="v">
                    @if ($lane['fleet'] > 0)
                        {{ $lane['fleet'] }} live supervisor{{ $lane['fleet'] === 1 ? '' : 's' }} fleet-wide
                        <i class="cond">· admission is not lane-scoped</i>
                    @else
                        <span class="text-{{ $w['headline']['tone'] }}">no live supervisor — nothing promotes this job</span>
                    @endif
                </span>
            </div>
        @endif

        {{-- 5. lane coverage: a gate for `queued`, forward-looking context otherwise --}}
        <div class="wrow">
            <span class="sdot h-{{ $dot($queued ? $lane['supervisors'] > 0 && $lane['free'] > 0 : null) }}"></span>
            <span class="k">lane {{ $lane['lane'] }}</span>
            <span class="v">
                @if ($lane['supervisors'] === 0)
                    <span class="text-{{ $queued ? $w['headline']['tone'] : 'amber' }}">no live supervisor on this lane</span>
                    <i class="cond">· {{ $queued ? 'nothing can claim it' : 'it will sit in queued once admitted' }}</i>
                @else
                    {{ $lane['supervisors'] }} supervisor{{ $lane['supervisors'] === 1 ? '' : 's' }} ·
                    {{ $lane['load'] }}/{{ $lane['capacity'] }} slots busy
                    @if ($lane['free'] === 0)<i class="cond">· saturated</i>@endif
                @endif
                @if ($lane['stale'] > 0)
                    <i class="cond">· {{ $lane['stale'] }} past its lease, not counted</i>
                @endif
                <i class="cond">·</i> <a class="cond" href="{{ route('jobwarden.workers') }}" wire:navigate>workers</a>
            </span>
        </div>

        {{-- 6. position in the claim order --}}
        @if ($queued && $w['ahead'] !== null)
            <div class="wrow">
                <span class="sdot h-{{ $dot($w['ahead']['count'] === 0) }}"></span>
                <span class="k">backlog</span>
                <span class="v">
                    @if ($w['ahead']['count'] === 0)
                        {{-- Never promise a claim the lane has nobody to make. --}}
                        <span class="muted">front of the lane{{ $lane['supervisors'] > 0 ? ' — claimed next' : '' }}</span>
                    @else
                        {{ $w['ahead']['count'] }}{{ $w['ahead']['capped'] ? '+' : '' }} queued job{{ $w['ahead']['count'] === 1 && ! $w['ahead']['capped'] ? '' : 's' }} claim first
                        <i class="cond">· priority DESC, then oldest first</i>
                    @endif
                </span>
            </div>
        @endif
    </div>

    @if ($orphanBlocked)
        <div class="wnote">
            An <b>orphaned</b> upstream is not terminal — a parked orphan awaits an operator verdict, so it gates its
            dependents under <i>either</i> edge condition, <span class="mono">on_completion</span> included. Restart or
            fail it to release this job.
        </div>
    @endif
</div>
