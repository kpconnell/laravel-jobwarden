<div @if ($live) wire:poll.2s="poll" @endif>
    <div class="logbar">
        <button type="button" class="btn sm" wire:click="toggleLive">
            <span class="sdot {{ $live ? 'h-green pulse' : 'h-gray' }}"></span>Live tail
        </button>
        <span class="note">GET /jobs/{id}/logs?after={cursor} · poll 2s</span>
    </div>
    @if ($logs->isEmpty())
        <div class="empty">No log lines yet.</div>
    @else
        <div class="logview" data-jw-autoscroll>
            @if ($truncated)
                <div class="logmeta">… earlier lines truncated (showing the last {{ $window }})</div>
            @endif
            @foreach ($logs as $l)
                <div class="logline">
                    <span class="seq">{{ $l->seq }}</span>
                    <span class="t">@include('jobwarden::partials.time', ['ms' => $l->ts_ms, 'mode' => 'time'])</span>
                    <span class="lvl lvl-{{ $l->level }}">{{ $l->level }}</span>
                    <span class="msg">{{ $l->body }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
