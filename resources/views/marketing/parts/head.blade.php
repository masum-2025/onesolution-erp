{{-- A section's eyebrow, title and intro. --}}
@if ($tx($d['eyebrow'] ?? null) !== '' || $tx($d['title'] ?? null) !== '')
    <div class="sec-head">
        @if ($tx($d['eyebrow'] ?? null) !== '')<span class="eyebrow">{{ $tx($d['eyebrow']) }}</span>@endif
        @if ($tx($d['title'] ?? null) !== '')<h2>{{ $tx($d['title']) }}</h2>@endif
        @if ($tx($d['intro'] ?? null) !== '')<p class="lead">{{ $tx($d['intro']) }}</p>@endif
    </div>
@endif
