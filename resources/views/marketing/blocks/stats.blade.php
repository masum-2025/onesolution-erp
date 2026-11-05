<section class="facts">
    <div class="wrap">
        @foreach ($d['items'] as $item)
            <div class="fact">
                @if ($item['value'] !== null)
                    <b data-count="{{ $item['value'] }}">{{ $number($item['value']) }}{{ $item['suffix'] ?? '' }}</b>
                @else
                    <b>{{ $tx($item['word'] ?? null) }}</b>
                @endif
                <span>{{ $tx($item['label']) }}</span>
            </div>
        @endforeach
    </div>
</section>
