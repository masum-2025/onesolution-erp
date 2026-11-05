<section class="sec" id="faq">
    <div class="wrap">
        @include('marketing.parts.head')
        <div class="faq">
            @foreach ($d['items'] as $item)
                <details @if ($loop->first) open @endif><summary>{{ $tx($item['question']) }}</summary><p>{{ $tx($item['answer']) }}</p></details>
            @endforeach
        </div>
    </div>
</section>
