@include('edu_website::blocks.title', ['eyebrow' => 'people'])
<div class="cards people">
    @foreach ($data['items'] ?? [] as $person)
        @php($photo = isset($person['image_media_id']) ? ($extra['media'][$person['image_media_id']] ?? null) : null)
        <div class="card">
            @if ($photo)
                <img src="{{ $photo['small'] }}" alt="" width="112" height="112" loading="lazy">
            @else
                <span class="mono" aria-hidden="true">{{ mb_strtoupper(mb_substr($person['name'] ?? '', 0, 1)) }}</span>
            @endif
            <h3>{{ $person['name'] ?? '' }}</h3>
            @if ($text($person['role'] ?? null) !== '')
                <div class="muted">{{ $text($person['role'] ?? null) }}</div>
            @endif
        </div>
    @endforeach
</div>
