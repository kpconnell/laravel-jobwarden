<aside class="sb" wire:poll.15s>
    <div class="sb-brand">
        <div class="sb-logo">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 1.5l5.5 2.5v4c0 3.2-2.3 5.6-5.5 6.5C4.8 13.6 2.5 11.2 2.5 8V4L8 1.5z" stroke="var(--accent-fg)" stroke-width="1.3" stroke-linejoin="round"/><path d="M5.6 8.1l1.7 1.7 3-3.4" stroke="var(--accent-fg)" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div style="min-width:0">
            <div class="sb-name">JobWarden</div>
            <div class="sb-sub">operator console</div>
        </div>
    </div>

    <div class="sb-env">
        <span class="sdot h-amber"></span>
        <b>env&nbsp;·&nbsp;{{ app()->environment() }}</b>
        <span>gate open</span>
    </div>

    <nav class="sb-nav">
        <a href="{{ route('jobwarden.overview') }}" wire:navigate class="sb-link {{ request()->routeIs('jobwarden.overview') ? 'active' : '' }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><rect x="1.6" y="1.6" width="5.2" height="5.2" rx="1.3" stroke="currentColor" stroke-width="1.2"/><rect x="9.2" y="1.6" width="5.2" height="5.2" rx="1.3" stroke="currentColor" stroke-width="1.2"/><rect x="1.6" y="9.2" width="5.2" height="5.2" rx="1.3" stroke="currentColor" stroke-width="1.2"/><rect x="9.2" y="9.2" width="5.2" height="5.2" rx="1.3" stroke="currentColor" stroke-width="1.2"/></svg>
            <span class="grow">Overview</span>
        </a>
        <a href="{{ route('jobwarden.jobs') }}" wire:navigate class="sb-link {{ request()->routeIs('jobwarden.jobs', 'jobwarden.jobs.show') ? 'active' : '' }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><rect x="1.5" y="2.6" width="13" height="2.8" rx="1" stroke="currentColor" stroke-width="1.2"/><rect x="1.5" y="6.6" width="13" height="2.8" rx="1" stroke="currentColor" stroke-width="1.2"/><rect x="1.5" y="10.6" width="13" height="2.8" rx="1" stroke="currentColor" stroke-width="1.2"/></svg>
            <span class="grow">Jobs</span>
            @if ($attention > 0)
                <span class="sb-badge red">{{ $attention }}</span>
            @elseif ($inFlight > 0)
                <span class="sb-badge">{{ $inFlight }}</span>
            @endif
        </a>
        <a href="{{ route('jobwarden.batches') }}" wire:navigate class="sb-link {{ request()->routeIs('jobwarden.batches', 'jobwarden.batches.show') ? 'active' : '' }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="3.3" cy="8" r="1.7" stroke="currentColor" stroke-width="1.2"/><circle cx="12.5" cy="3.6" r="1.7" stroke="currentColor" stroke-width="1.2"/><circle cx="12.5" cy="12.4" r="1.7" stroke="currentColor" stroke-width="1.2"/><path d="M4.8 7.2l6.3-3M4.8 8.8l6.3 3" stroke="currentColor" stroke-width="1.2"/></svg>
            <span class="grow">Batches</span>
            @if ($runningBatches > 0)<span class="sb-badge">{{ $runningBatches }}</span>@endif
        </a>
        <a href="{{ route('jobwarden.schedules') }}" wire:navigate class="sb-link {{ request()->routeIs('jobwarden.schedules', 'jobwarden.schedules.show') ? 'active' : '' }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.3" stroke="currentColor" stroke-width="1.2"/><path d="M8 4.5V8l2.6 1.6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
            <span class="grow">Schedules</span>
            @if ($enabledSchedules > 0)<span class="sb-badge">{{ $enabledSchedules }}</span>@endif
        </a>
        <a href="{{ route('jobwarden.workers') }}" wire:navigate class="sb-link {{ request()->routeIs('jobwarden.workers') ? 'active' : '' }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><rect x="1.8" y="2.3" width="12.4" height="4.3" rx="1.2" stroke="currentColor" stroke-width="1.2"/><rect x="1.8" y="9.4" width="12.4" height="4.3" rx="1.2" stroke="currentColor" stroke-width="1.2"/><circle cx="4.3" cy="4.45" r=".85" fill="currentColor"/><circle cx="4.3" cy="11.55" r=".85" fill="currentColor"/></svg>
            <span class="grow">Workers</span>
            @if ($liveWorkers > 0)<span class="sb-badge">{{ $liveWorkers }}</span>@endif
        </a>
    </nav>

    <div class="sb-foot">
        <div class="sb-health {{ $deadSupervisors > 0 ? 'bad' : '' }}">
            <span class="sdot {{ $deadSupervisors > 0 ? 'h-red' : 'h-green' }}"></span>
            <span>
                @if ($deadSupervisors > 0)
                    {{ $deadSupervisors }} dead supervisor{{ $deadSupervisors === 1 ? '' : 's' }}
                @elseif ($liveSupervisors > 0)
                    {{ $liveSupervisors }} supervisor{{ $liveSupervisors === 1 ? '' : 's' }} live
                @else
                    no live supervisors
                @endif
            </span>
        </div>
        <div class="sb-tools">
            <button type="button" class="sb-tbtn" onclick="jwToggleTheme()" title="Toggle theme">
                <span class="mono" data-jw-theme-icon>◐</span><span data-jw-theme-label>Dark</span>
            </button>
            <button type="button" class="sb-tbtn icon" onclick="jwToggleDensity()" title="Toggle density">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            </button>
        </div>
    </div>
</aside>
