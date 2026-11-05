<section class="wrap offer-wrap" id="offer">
    <div class="offer">
        <div>
            @if ($tx($d['tag'] ?? null) !== '')<span class="otag">{{ $tx($d['tag']) }}</span>@endif
            <h3>{{ $tx($d['title']) }}</h3>
            @if ($tx($d['text'] ?? null) !== '')<p>{{ $tx($d['text']) }}</p>@endif
        </div>
        @isset($block['countdown'])
            <div class="count" data-ends="{{ $block['countdown']['ends'] }}" aria-label="{{ $ui('offer_ends') }}">
                <div><b>{{ $number($block['countdown']['days']) }}</b><small>{{ $ui('days') }}</small></div>
                <div><b>{{ $number($block['countdown']['hours']) }}</b><small>{{ $ui('hours') }}</small></div>
                <div><b>{{ $number($block['countdown']['minutes']) }}</b><small>{{ $ui('minutes') }}</small></div>
            </div>
        @endisset
        @if ($tx($d['button_label'] ?? null) !== '')<a class="btn btn-w" href="{{ $link($d['button_url'] ?? null) }}">{{ $tx($d['button_label']) }} →</a>@endif
    </div>
</section>
