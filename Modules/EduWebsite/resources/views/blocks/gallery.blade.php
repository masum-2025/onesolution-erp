@include('edu_website::blocks.title', ['eyebrow' => 'gallery'])
<div class="gallery">
    @foreach ($data['media_ids'] ?? [] as $id)
        @if (isset($extra['media'][$id]) && $extra['media'][$id]['kind'] === 'image')
            <a href="{{ $extra['media'][$id]['large'] }}"><img src="{{ $extra['media'][$id]['small'] }}" alt="{{ $extra['media'][$id]['name'] }}" loading="lazy" width="480" height="360"></a>
        @endif
    @endforeach
</div>
