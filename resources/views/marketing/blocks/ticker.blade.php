@if ($block['labels'] !== [])
    <section class="ticker" aria-label="{{ $ui('modules') }}">
        <div class="track">
            @foreach ($block['labels'] as $label)<span>{{ $label }}</span>@endforeach
            @foreach ($block['labels'] as $label)<span aria-hidden="true">{{ $label }}</span>@endforeach
        </div>
    </section>
@endif
