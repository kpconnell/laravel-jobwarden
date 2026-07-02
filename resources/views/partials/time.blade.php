{{--
    Timezone-correct timestamp. $ms is Unix-epoch-milliseconds produced in SQL
    (JobWardenModel::scopeWithDisplayEpochs), so it is the true instant regardless of the
    app or DB session timezone. The browser rewrites the text into the viewer's own zone
    (see the jw-time script in the layout); the server-rendered text is the no-JS fallback.

    @param int|string|null $ms    epoch milliseconds, or null → em dash
    @param string          $mode  'relative' ("5 min ago") | 'time' (HH:MM:SS)
--}}
@php
    $jwMs = (isset($ms) && $ms !== null && $ms !== '') ? (int) $ms : null;
    $jwMode = $mode ?? 'relative';
@endphp
@if ($jwMs !== null)@php($jwAt = \Illuminate\Support\Carbon::createFromTimestampMs($jwMs, 'UTC'))<time datetime="{{ $jwAt->toIso8601String() }}" data-jw-epoch="{{ $jwMs }}" data-jw-time="{{ $jwMode }}" title="{{ $jwAt->toDayDateTimeString() }} UTC">{{ $jwMode === 'time' ? $jwAt->format('H:i:s') : $jwAt->diffForHumans() }}</time>@else<span class="muted">—</span>@endif
