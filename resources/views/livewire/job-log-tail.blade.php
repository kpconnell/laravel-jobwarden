<div @if ($live) wire:poll.2s="poll" @endif>
    <div class="logbar">
        <button type="button" class="btn sm" wire:click="toggleLive">
            <span class="sdot {{ $live ? 'h-green pulse' : 'h-gray' }}"></span>Live tail
        </button>
        <select wire:model.live="since" aria-label="Log time window">
            @foreach ($windows as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <span class="note">GET /jobs/{id}/logs?after={cursor} · poll 2s</span>
    </div>
    @if ($logs->isEmpty())
        <div class="empty">{{ $since === 'all' ? 'No log lines yet.' : 'Nothing logged in the '.strtolower($windows[$since]).'.' }}</div>
    @else
        <div class="logview">
            @if ($truncated)
                <div class="logmeta">… earlier lines truncated (showing the last {{ $window }})</div>
            @endif
            @foreach ($logs as $l)
                <div class="logline">
                    <span class="seq">{{ $l->seq }}</span>
                    <span class="t">@include('jobwarden::partials.time', ['ms' => $l->ts_ms, 'mode' => 'time'])</span>
                    <span class="lvl lvl-{{ $l->level }}">{{ $l->level }}</span>
                    {{-- .msg is pre-wrap: keep this on one source line or stray whitespace renders --}}
                    <span class="msg">@if ($l->step)<span class="stp">[{{ $l->step }}]</span> @endif{{ $l->body }}@if ($l->context !== null) <span class="ctx">{{ $l->context }}</span>@endif</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
