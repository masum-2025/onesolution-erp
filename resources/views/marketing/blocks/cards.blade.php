<section class="sec" id="solutions">
    <div class="wrap">
        @include('marketing.parts.head')
        <div class="sectors">
            @foreach ($d['items'] as $item)
                <{{ filled($item['url'] ?? null) ? 'a' : 'div' }} class="card" @if (filled($item['url'] ?? null)) href="{{ $link($item['url']) }}" @endif>
                    <div class="ico c-{{ $item['color'] }}">@include('marketing.parts.icon', ['name' => $item['icon']])</div>
                    <h3>{{ $tx($item['title']) }}</h3>
                    @if ($tx($item['text'] ?? null) !== '')<p>{{ $tx($item['text']) }}</p>@endif
                    @if (filled($item['url'] ?? null))<span class="more">{{ $ui('explore') }} →</span>@endif
                </{{ filled($item['url'] ?? null) ? 'a' : 'div' }}>
            @endforeach
        </div>
    </div>
</section>
