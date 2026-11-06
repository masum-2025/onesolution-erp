@php($pic = $picture($d['image'] ?? null))
<section class="sec">
    <div class="wrap prose">
        @include('marketing.parts.head')
        @if ($pic)
            <figure class="prose-pic"><img src="{{ $pic['src'] }}" srcset="{{ $pic['srcset'] }}" sizes="(max-width: 760px) 100vw, 760px" width="{{ $pic['width'] }}" height="{{ $pic['height'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async"></figure>
        @endif
        @foreach (preg_split('/\n\s*\n/', $tx($d['body'])) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>
</section>
