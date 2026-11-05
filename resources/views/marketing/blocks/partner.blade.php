<section class="sec" id="partners">
    <div class="wrap">
        <div class="partner">
            <div class="partner-copy">
                @if ($tx($d['eyebrow'] ?? null) !== '')<span class="eyebrow on-dark">{{ $tx($d['eyebrow']) }}</span>@endif
                <h2 class="sec-title">{{ $tx($d['title'] ?? null) }}</h2>
                @if ($tx($d['text'] ?? null) !== '')<p>{{ $tx($d['text']) }}</p>@endif
                @if ($tx($d['button_label'] ?? null) !== '')<div class="cta"><a class="btn btn-w" href="{{ $link($d['button_url'] ?? null) }}">{{ $tx($d['button_label']) }} →</a></div>@endif
            </div>
            @if (! empty($d['steps']))
                <div class="steps">
                    @foreach ($d['steps'] as $step)
                        <div><b>{{ $number($loop->iteration) }}</b>{{ $tx($step) }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
