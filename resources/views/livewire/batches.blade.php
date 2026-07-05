<div class="view" wire:poll.{{ config('jobwarden.dashboard.poll', '10s') }}>
    <div class="tbl-head batches-grid" style="padding:0 16px">
        <span>State</span><span>Name</span><span>Policy</span><span>Progress</span><span>Total</span><span>Source</span>
    </div>
    <div class="tbl-scroll">
        @forelse ($batches as $b)
            @php($total = max(1, (int) $b->total_jobs))
            <div class="tbl-row batches-grid click" style="padding:0 16px;min-height:44px" data-jw-href="{{ route('jobwarden.batches.show', $b->id) }}" wire:key="batch-{{ $b->id }}">
                @include('jobwarden::partials.state-badge', ['state' => $b->state])
                <div class="cell-main">
                    <div class="t mono">{{ $b->name ?? class_basename((string) $b->type) ?: 'batch' }}</div>
                    <div class="s">{{ \Illuminate\Support\Str::substr($b->id, 0, 13) }}… @if($b->type) · {{ $b->type }} @endif</div>
                </div>
                @php($policyHue = match ($b->failure_policy) { 'fail_fast' => 'red', 'threshold' => 'amber', default => 'slate' })
                <span class="badge h-{{ $policyHue }}">{{ $b->failure_policy }}</span>
                <div style="display:flex;align-items:center;gap:8px">
                    <div class="bar thin" style="flex:1">
                        <div class="fill-green" style="width:{{ round($b->succeeded_count / $total * 100, 2) }}%"></div>
                        <div class="fill-red" style="width:{{ round($b->failed_count / $total * 100, 2) }}%"></div>
                        <div class="fill-gray" style="width:{{ round($b->canceled_count / $total * 100, 2) }}%"></div>
                        <div class="fill-blue" style="width:{{ round($b->running_count / $total * 100, 2) }}%"></div>
                    </div>
                    <span class="cell-dim" style="width:38px">{{ round(($b->succeeded_count + $b->failed_count + $b->canceled_count) / $total * 100) }}%</span>
                </div>
                <span class="mono" style="font-size:12px;font-weight:500">{{ number_format($b->total_jobs) }}</span>
                <span class="cell-txt">{{ $b->created_by ?? '—' }}</span>
            </div>
        @empty
            <div class="empty">No batches yet.</div>
        @endforelse
    </div>
</div>
