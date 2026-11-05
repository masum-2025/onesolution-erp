<section class="sec">
    <div class="wrap prose">
        @include('marketing.parts.head')
        @foreach (preg_split('/\n\s*\n/', $tx($d['body'])) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>
</section>
