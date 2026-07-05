{{-- Static shell chrome: view title, the endpoint that feeds the screen, the
     global search (tag grammar → Jobs q), refresh. No Livewire state here —
     search submits navigate to the Jobs screen; refresh pings every component. --}}
<header class="tb">
    <div class="tb-left">
        <span class="tb-title">{{ $jwTitle }}</span>
        @if ($jwEndpoint)
            <span class="tb-pill">{{ $jwEndpoint }}</span>
        @endif
    </div>
    <div class="tb-mid">
        <form class="tb-search" data-jw-search action="{{ route('jobwarden.jobs') }}" method="get">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="currentColor" stroke-width="1.3"/><path d="M11 11l3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            <input name="q" value="{{ request()->query('q', '') }}" placeholder="Search  ·  name:value  date:2026-07*  free text" autocomplete="off" spellcheck="false">
        </form>
    </div>
    <div class="tb-right">
        <span class="tb-updated">updated <span data-jw-updated>just now</span></span>
        <button type="button" class="tb-refresh" data-jw-refresh title="Refresh">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M13.6 8a5.6 5.6 0 1 1-1.6-3.9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M13.8 2.2v3.2h-3.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>
</header>
