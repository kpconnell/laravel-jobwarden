{{-- Semantic state badge. $state: enum|string. Optional $label, $pulse.
     Hues are shared across jobs/attempts/batches/workers/schedule-runs. --}}
@php
    $jwState = (string) ($state instanceof \BackedEnum ? $state->value : $state);
    $jwHue = match ($jwState) {
        'succeeded', 'active', 'enqueued' => 'green',
        'failed', 'dead' => 'red',
        'running', 'queued', 'dispatched', 'draining' => 'blue',
        'retrying', 'partial', 'starting', 'coalesced', 'overlapped' => 'amber',
        'orphaned' => 'purple',
        'canceled', 'stopped', 'skipped' => 'gray',
        default => 'slate',
    };
    $jwPulse = $pulse ?? in_array($jwState, ['running', 'draining'], true);
@endphp
<span class="badge h-{{ $jwHue }}"><span class="sdot {{ $jwPulse ? 'pulse' : '' }}"></span>{{ $label ?? $jwState }}</span>
