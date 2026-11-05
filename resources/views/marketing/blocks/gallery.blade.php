<section class="sec" id="gallery">
    <div class="wrap">
        @include('marketing.parts.head')
        <div class="gal">
            @foreach ($d['items'] as $item)
                <figure @class(['g1' => $loop->first])><div class="shot c-{{ $item['color'] }}"><i></i><i></i><i></i></div><figcaption>{{ $tx($item['caption']) }}</figcaption></figure>
            @endforeach
        </div>
    </div>
</section>
