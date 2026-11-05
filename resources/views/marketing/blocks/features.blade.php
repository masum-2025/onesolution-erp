<section class="sec">
    <div class="wrap split">
        <div>
            @if ($tx($d['eyebrow'] ?? null) !== '')<span class="eyebrow">{{ $tx($d['eyebrow']) }}</span>@endif
            @if ($tx($d['title'] ?? null) !== '')<h2 class="sec-title">{{ $tx($d['title']) }}</h2>@endif
            @if ($tx($d['intro'] ?? null) !== '')<p class="lead mt">{{ $tx($d['intro']) }}</p>@endif
            <ul class="list plain">
                @foreach ($d['items'] as $item)
                    <li><span class="ico c-{{ $item['color'] }}">@include('marketing.parts.icon', ['name' => $item['icon']])</span><div><b>{{ $tx($item['title']) }}</b><span>{{ $tx($item['text'] ?? null) }}</span></div></li>
                @endforeach
            </ul>
        </div>
        @if (! empty($d['panel']))
            <div class="panel">
                <small>{{ $tx($d['panel_title'] ?? null) }}</small>
                @foreach ($d['panel'] as $row)
                    <div class="row"><span>{{ $tx($row['label']) }}</span><small>{{ $tx($row['value'] ?? null) }}</small></div>
                @endforeach
            </div>
        @endif
    </div>
</section>
