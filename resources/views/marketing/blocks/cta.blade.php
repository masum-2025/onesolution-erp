<section class="wrap cta-wrap" id="contact">
    <div class="final">
        <h2>{{ $tx($d['title']) }}</h2>
        @if ($tx($d['text'] ?? null) !== '')<p class="lead mt">{{ $tx($d['text']) }}</p>@endif
        <div class="cta">
            @if ($tx($d['primary_label'] ?? null) !== '')<a class="btn btn-p" href="{{ $link($d['primary_url'] ?? null) }}">{{ $tx($d['primary_label']) }} →</a>@endif
            @if ($tx($d['secondary_label'] ?? null) !== '')<a class="btn btn-g" href="{{ $link($d['secondary_url'] ?? null) }}">{{ $tx($d['secondary_label']) }}</a>@endif
        </div>
    </div>
</section>
