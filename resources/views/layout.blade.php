<!DOCTYPE html>
<html lang="en" data-theme="dark" data-density="compact">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        [$jwTitle, $jwEndpoint] = match (request()->route()?->getName()) {
            'jobwarden.overview' => ['Overview', 'GET /stats'],
            'jobwarden.jobs' => ['Jobs', 'GET /jobs'],
            'jobwarden.jobs.show' => ['Job detail', 'GET /jobs/{id}'],
            'jobwarden.batches' => ['Batches', 'GET /batches'],
            'jobwarden.batches.show' => ['Batch detail', 'GET /batches/{id}'],
            'jobwarden.schedules' => ['Schedules', 'GET /schedules'],
            'jobwarden.schedules.show' => ['Schedule detail', 'GET /schedules/{id}'],
            'jobwarden.workers' => ['Workers', 'GET /workers'],
            default => ['JobWarden', null],
        };
    @endphp
    <title>{{ $jwTitle }} · JobWarden</title>
    {{-- Apply persisted theme/density before the stylesheet paints (no FOUC).
         The attributes live on <html>, which wire:navigate never replaces. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('jw-theme');
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
                var d = localStorage.getItem('jw-density');
                if (d === 'comfortable' || d === 'compact') document.documentElement.setAttribute('data-density', d);
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @include('jobwarden::partials.style')
    @livewireStyles
</head>
<body>
    <div class="jw-app">
        @livewire('jobwarden.sidebar')
        <div class="jw-main">
            @include('jobwarden::partials.topbar', ['jwTitle' => $jwTitle, 'jwEndpoint' => $jwEndpoint])
            <main class="jw-content">
                {{ $slot }}
            </main>
        </div>
    </div>
    <div id="jw-toast" hidden>
        <span class="sdot h-green" data-jw-toast-dot></span>
        <span class="t-msg" data-jw-toast-msg></span>
        <span class="t-detail" data-jw-toast-detail></span>
    </div>
    @livewireScripts
    @include('jobwarden::partials.scripts')
</body>
</html>
