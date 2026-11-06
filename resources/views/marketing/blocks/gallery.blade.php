<section class="sec" id="gallery">
    <div class="wrap">
        @include('marketing.parts.head')
        <div class="gal">
            @foreach ($d['items'] as $item)
                @php($pic = $picture($item['image'] ?? null))
                <figure @class(['g1' => $loop->first])>
                    @if ($pic)
                        <img src="{{ $loop->first ? $pic['src'] : $pic['small'] }}" srcset="{{ $pic['srcset'] }}" sizes="{{ $loop->first ? '(max-width: 760px) 100vw, 50vw' : '(max-width: 760px) 50vw, 25vw' }}" width="{{ $pic['width'] }}" height="{{ $pic['height'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async">
                    @else
                        <div class="shot c-{{ $item['color'] }}"><i></i><i></i><i></i></div>
                    @endif
                    <figcaption>{{ $tx($item['caption']) }}</figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
