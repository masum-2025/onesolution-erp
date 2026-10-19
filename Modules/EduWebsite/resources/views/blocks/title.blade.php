@php($heading = $text($data['title'] ?? null))
@if ($heading !== '' || isset($eyebrow))
    <div class="head">
        @isset($eyebrow)
            <span class="eyebrow">{{ __("edu_website::public.eyebrow_{$eyebrow}", [], $locale) }}</span>
        @endisset
        @if ($heading !== '')
            <h2 class="title">{{ $heading }}</h2>
        @endif
        <span class="line" aria-hidden="true"></span>
    </div>
@endif
