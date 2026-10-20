@php
    // Pictures with captions (items), or the ids alone as blocks made before captions (media_ids).
    $pictures = collect($data['items'] ?? array_map(fn ($id) => ['image_media_id' => $id], $data['media_ids'] ?? []))
        ->map(fn ($item) => ['media' => $extra['media'][$item['image_media_id'] ?? ''] ?? null, 'caption' => $text($item['caption'] ?? null)])
        ->filter(fn ($item) => $item['media'] !== null && $item['media']['kind'] === 'image')->values();
    $layout = in_array($data['layout'] ?? 'grid', ['masonry', 'carousel'], true) ? $data['layout'] : 'grid';
    $ui = fn (string $key) => __("edu_website::public.{$key}", [], $locale);
@endphp
@include('edu_website::blocks.title', ['eyebrow' => 'gallery'])
<div class="gallery {{ $layout }}">
    @if ($layout === 'carousel')
        <button type="button" class="prev" aria-label="{{ $ui('previous') }}" hidden>@include('edu_website::icon', ['name' => 'left'])</button>
    @endif
    <ul class="plain strip">
        @foreach ($pictures as $picture)
            <li>
                {{-- Without the script the large picture opens on its own; with it, in the viewer. --}}
                <a href="{{ $picture['media']['large'] }}" data-full data-caption="{{ $picture['caption'] }}">
                    <img
                        {{-- The first picture of a grid is shown large: its large copy. --}}
                        src="{{ $layout === 'grid' && $loop->first ? $picture['media']['large'] : $picture['media']['small'] }}"
                        alt="{{ $picture['caption'] !== '' ? $picture['caption'] : $picture['media']['name'] }}"
                        loading="lazy"
                        width="{{ $picture['media']['width'] ?? 480 }}"
                        height="{{ $picture['media']['height'] ?? 360 }}"
                    >
                    <span class="zoom" aria-hidden="true">@include('edu_website::icon', ['name' => 'zoom'])</span>
                    @if ($picture['caption'] !== '')
                        <span class="caption">{{ $picture['caption'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
    @if ($layout === 'carousel')
        <button type="button" class="next" aria-label="{{ $ui('next') }}" hidden>@include('edu_website::icon', ['name' => 'right'])</button>
    @endif
</div>
