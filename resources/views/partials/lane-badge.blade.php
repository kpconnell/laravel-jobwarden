@php
    $jwLaneHue = match ($lane) {
        'scheduled' => 'teal',
        'mission-critical' => 'red',
        'default' => 'slate',
        default => 'gray',
    };
@endphp
<span class="badge h-{{ $jwLaneHue }}">{{ $lane }}</span>
