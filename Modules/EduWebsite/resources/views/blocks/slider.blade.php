@php
    $slides = collect($data['slides'] ?? [])->filter(fn ($slide) => isset($extra['media'][$slide['image_media_id'] ?? '']) && $extra['media'][$slide['image_media_id']]['kind'] === 'image')->values();
    $ui = fn (string $key, array $replace = []) => __("edu_website::public.{$key}", $replace, $locale);
@endphp
@if ($slides->isNotEmpty())
    {{-- Without the script the slides scroll sideways (swipe); with it they turn by themselves, with arrows and dots. --}}
    <div
        class="slider fx-{{ ($data['effect'] ?? 'fade') === 'slide' ? 'slide' : 'fade' }} h-{{ ($data['height'] ?? 'normal') === 'full' ? 'full' : 'normal' }}"
        data-autoplay="{{ ($data['autoplay'] ?? true) ? '1' : '0' }}"
        data-interval="{{ (int) ($data['interval'] ?? 5) }}"
        role="region"
        aria-roledescription="{{ $ui('slider') }}"
        aria-label="{{ $ui('slider') }}"
        tabindex="0"
    >
        <div class="track">
            @foreach ($slides as $at => $slide)
                @php($image = $extra['media'][$slide['image_media_id']])
                <figure class="slide {{ $at === 0 ? 'on' : '' }}" aria-roledescription="{{ $ui('slide') }}" aria-label="{{ $ui('slide_of', ['number' => $number($at + 1), 'count' => $number($slides->count())]) }}">
                    <img
                        src="{{ $image['large'] }}"
                        alt=""
                        width="{{ $image['width'] ?? 1600 }}"
                        height="{{ $image['height'] ?? 900 }}"
                        @if ($at === 0) fetchpriority="high" @else loading="lazy" @endif
                    >
                    <span class="shade" aria-hidden="true"></span>
                    @if ($text($slide['title'] ?? null) !== '' || $text($slide['text'] ?? null) !== '')
                        <figcaption class="wrap">
                            <div class="cap">
                                @if ($text($slide['title'] ?? null) !== '')
                                    @if (($first ?? false) && $at === 0)
                                        <h1>{{ $text($slide['title']) }}</h1>
                                    @else
                                        <h2>{{ $text($slide['title']) }}</h2>
                                    @endif
                                @endif
                                @if ($text($slide['text'] ?? null) !== '')
                                    <p>{{ $text($slide['text']) }}</p>
                                @endif
                                @if (! empty($slide['button_url']) && $text($slide['button_label'] ?? null) !== '')
                                    <a class="btn" href="{{ $slide['button_url'] }}">{{ $text($slide['button_label']) }} @include('edu_website::icon', ['name' => 'arrow'])</a>
                                @endif
                            </div>
                        </figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
        @if ($slides->count() > 1)
            <button type="button" class="prev" aria-label="{{ $ui('previous') }}" hidden>@include('edu_website::icon', ['name' => 'left'])</button>
            <button type="button" class="next" aria-label="{{ $ui('next') }}" hidden>@include('edu_website::icon', ['name' => 'right'])</button>
            <div class="dots" hidden>
                @foreach ($slides as $at => $slide)
                    <button type="button" aria-label="{{ $ui('go_slide', ['number' => $number($at + 1)]) }}" aria-current="{{ $at === 0 ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
        @endif
    </div>
@endif
