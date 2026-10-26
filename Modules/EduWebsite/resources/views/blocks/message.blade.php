@php
    $body = $text($data['body'] ?? null);
    $image = isset($data['image_media_id']) ? ($extra['media'][$data['image_media_id']] ?? null) : null;
@endphp
{{-- Shown once the message is written (a new website starts with this block empty). --}}
@if ($body !== '')
    @include('edu_website::blocks.title', ['eyebrow' => 'message'])
    <figure class="message">
        @if ($image && $image['kind'] === 'image')
            <img class="portrait" src="{{ $image['small'] }}" alt="{{ $data['name'] ?? '' }}" width="{{ $image['width'] ?? 480 }}" height="{{ $image['height'] ?? 480 }}" loading="lazy">
        @endif
        <div class="words">
            <blockquote>{{ $body }}</blockquote>
            @if (($data['name'] ?? '') !== '' || $text($data['role'] ?? null) !== '')
                <figcaption><b>{{ $data['name'] ?? '' }}</b>@if ($text($data['role'] ?? null) !== '')<span>{{ $text($data['role']) }}</span>@endif</figcaption>
            @endif
        </div>
    </figure>
@endif
