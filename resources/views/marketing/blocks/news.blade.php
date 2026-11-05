<section class="sec" id="news">
    <div class="wrap">
        @include('marketing.parts.head')
        <div class="news">
            @foreach ($d['items'] as $item)
                <{{ filled($item['url'] ?? null) ? 'a' : 'article' }} class="card" @if (filled($item['url'] ?? null)) href="{{ $link($item['url']) }}" @endif>
                    <time datetime="{{ $item['date'] }}">{{ $date($item['date']) }}</time>
                    <h3>{{ $tx($item['title']) }}</h3>
                    @if ($tx($item['text'] ?? null) !== '')<p>{{ $tx($item['text']) }}</p>@endif
                </{{ filled($item['url'] ?? null) ? 'a' : 'article' }}>
            @endforeach
        </div>
    </div>
</section>
