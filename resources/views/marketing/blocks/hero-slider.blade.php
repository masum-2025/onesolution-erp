@php($slides = $d['slides'])
<section class="hero" data-slider data-effect="{{ $d['effect'] }}" data-seconds="{{ $d['seconds'] }}" data-autoplay="{{ $d['autoplay'] ? 'true' : 'false' }}" aria-roledescription="carousel">
    <div class="slides">
        @foreach ($slides as $slide)
            <div @class(['slide', 'on' => $loop->first]) aria-roledescription="slide" aria-label="{{ $ui('slide_of', ['n' => $number($loop->iteration), 'total' => $number(count($slides))]) }}">
                <div class="wrap hero-grid">
                    <div class="copy">
                        @if ($tx($slide['eyebrow'] ?? null) !== '')<span class="eyebrow"><span class="dot"></span>{{ $tx($slide['eyebrow']) }}</span>@endif
                        @php($words = array_values(array_filter(array_map($tx, $slide['rotating'] ?? []))))
                        <{{ $loop->first ? 'h1' : 'h2' }} @class(['h1' => ! $loop->first])>
                            {{ $tx($slide['title']) }}
                            @if ($words !== [])
                                <span class="rot" aria-hidden="true">@foreach ($words as $word)<em>{{ $word }}</em>@endforeach</span>
                                <span class="skip">{{ implode(', ', $words) }}</span>
                            @elseif ($tx($slide['highlight'] ?? null) !== '')
                                <em>{{ $tx($slide['highlight']) }}</em>
                            @endif
                        </{{ $loop->first ? 'h1' : 'h2' }}>
                        @if ($tx($slide['text'] ?? null) !== '')<p class="lead">{{ $tx($slide['text']) }}</p>@endif
                        <div class="cta">
                            @if ($tx($slide['primary_label'] ?? null) !== '')<a class="btn btn-p" href="{{ $link($slide['primary_url'] ?? null) }}">{{ $tx($slide['primary_label']) }} →</a>@endif
                            @if ($tx($slide['secondary_label'] ?? null) !== '')<a class="btn btn-g" href="{{ $link($slide['secondary_url'] ?? null) }}">{{ $tx($slide['secondary_label']) }}</a>@endif
                        </div>
                        @if (! empty($slide['ticks']))
                            <ul class="ticks">
                                @foreach ($slide['ticks'] as $tick)
                                    <li><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>{{ $tx($tick) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @php($pic = $picture($slide['image'] ?? null))
                    @if ($pic)
                        {{-- The first slide's picture is what a visitor sees first: fetched straight away. --}}
                        <div class="vis slide-pic"><img src="{{ $pic['src'] }}" srcset="{{ $pic['srcset'] }}" sizes="(max-width: 760px) 100vw, 50vw" width="{{ $pic['width'] }}" height="{{ $pic['height'] }}" alt="{{ $pic['alt'] }}" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async"></div>
                    @else
                        @include('marketing.parts.slide-visual', ['visual' => $slide['visual']])
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    @if (count($slides) > 1)
        <div class="wrap dots">
            <span class="dotset">@foreach ($slides as $slide)<button type="button" @class(['on' => $loop->first]) aria-label="{{ $ui('slide_of', ['n' => $number($loop->iteration), 'total' => $number(count($slides))]) }}"></button>@endforeach</span>
            <span class="arrows"><button type="button" data-prev aria-label="{{ $ui('previous') }}">‹</button><button type="button" data-next aria-label="{{ $ui('next') }}">›</button></span>
        </div>
    @endif
</section>
