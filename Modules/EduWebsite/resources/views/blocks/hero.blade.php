@php($image = isset($data['image_media_id']) ? ($extra['media'][$data['image_media_id']] ?? null) : null)
<div class="hero {{ $image ? 'has-img' : '' }}">
    @if ($image)
        <img class="bg" src="{{ $image['small'] }}" alt="" aria-hidden="true">
    @endif
    <span class="glow" aria-hidden="true"></span>
    <div class="wrap">
        <div>
            <h1>{{ $text($data['title'] ?? null) }}</h1>
            @if ($text($data['text'] ?? null) !== '')
                <p>{{ $text($data['text'] ?? null) }}</p>
            @endif
            <div class="actions">
                @if (! empty($data['button_url']) && $text($data['button_label'] ?? null) !== '')
                    <a class="btn" href="{{ $data['button_url'] }}">{{ $text($data['button_label'] ?? null) }} @include('edu_website::icon', ['name' => 'arrow'])</a>
                @endif
                <a class="btn ghost" href="{{ $base }}/notices{{ $query ?? '' }}">@include('edu_website::icon', ['name' => 'bell']) {{ __('edu_website::public.notices', [], $locale) }}</a>
            </div>
        </div>
        @if ($image)
            <img class="pic" src="{{ $image['large'] }}" alt="" width="{{ $image['width'] }}" height="{{ $image['height'] }}" fetchpriority="high">
        @endif
    </div>
    <svg class="wave" viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 40c240 30 480 30 720 0s480-30 720 0v30H0z"/></svg>
</div>
