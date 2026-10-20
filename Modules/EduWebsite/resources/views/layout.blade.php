@php
    $q = $query ?? '';
    $ui = fn (string $key, array $replace = []) => __("edu_website::public.{$key}", $replace, $locale);
    $home = $base === '' ? '/' : $base;
    $initial = mb_strtoupper(mb_substr($title, 0, 1));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Platform\Support\LocaleResolver::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim(($pageTitle ?? '') !== '' ? $pageTitle.' · '.$title : $title) }}</title>
    @if ($tagline !== '')
        <meta name="description" content="{{ $tagline }}">
    @endif
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:type" content="website">
    @if ($logo)
        <link rel="icon" href="{{ $logo }}">
    @endif
    @if (! empty($q))
        <meta name="robots" content="noindex">
    @endif
    {{-- Our own stylesheet (no characters escaping would change: PublicSite::css checks), allowed by its hash in the CSP. --}}
    <style>{{ $css }}</style>
</head>
<body class="tpl-{{ $site->template }}">
<a class="skip" href="#main">{{ $ui('skip') }}</a>

<div class="topbar">
    <div class="wrap">
        @if ($contact['phone'])
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">@include('edu_website::icon', ['name' => 'phone']){{ $contact['phone'] }}</a>
        @endif
        @if ($contact['email'])
            <a href="mailto:{{ $contact['email'] }}">@include('edu_website::icon', ['name' => 'mail']){{ $contact['email'] }}</a>
        @endif
        <span class="grow"></span>
        @if (count($site->locales ?? []) > 1)
            <div class="langs" aria-label="{{ $ui('language') }}">
                @foreach ($site->locales as $code)
                    <a href="?{{ http_build_query([...request()->only(['expires', 'signature']), 'lang' => $code]) }}" lang="{{ $code }}" @if ($code === $locale) aria-current="true" @endif>{{ \Illuminate\Support\Facades\Lang::has("edu_website::public.lang_{$code}") ? __("edu_website::public.lang_{$code}") : strtoupper($code) }}</a>
                @endforeach
            </div>
        @endif
    </div>
</div>

<header class="top">
    <div class="wrap bar">
        <a class="brand" href="{{ $home.$q }}">
            @if ($logo)
                <img src="{{ $logo }}" alt="" width="52" height="52">
            @else
                <span class="mono" aria-hidden="true">{{ $initial }}</span>
            @endif
            <span><b>{{ $title }}</b>@if ($tagline !== '')<small>{{ $tagline }}</small>@endif</span>
        </a>
        <nav class="menu wide" aria-label="{{ $ui('menu') }}">
            <ul>
                @foreach ($menu as $item)
                    <li><a href="{{ $item['url'].$q }}" @if (request()->getPathInfo() === parse_url($item['url'], PHP_URL_PATH)) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                @endforeach
                <li><a href="{{ $base }}/notices{{ $q }}" @if (str_ends_with(request()->getPathInfo(), '/notices')) aria-current="page" @endif>{{ $ui('notices') }}</a></li>
            </ul>
        </nav>
        <details class="nav">
            <summary>{{ $ui('menu') }}</summary>
            <ul class="plain">
                @foreach ($menu as $item)
                    <li><a href="{{ $item['url'].$q }}">{{ $item['label'] }}</a></li>
                @endforeach
                <li><a href="{{ $base }}/notices{{ $q }}">{{ $ui('notices') }}</a></li>
            </ul>
        </details>
    </div>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="foot">
    <div class="wrap">
        <div class="cols">
            <div>
                <h3>{{ $title }}</h3>
                @if ($tagline !== '')
                    <p>{{ $tagline }}</p>
                @endif
            </div>
            <div>
                <h3>{{ $ui('quick_links') }}</h3>
                <ul class="plain">
                    @foreach ($menu as $item)
                        <li><a href="{{ $item['url'].$q }}">{{ $item['label'] }}</a></li>
                    @endforeach
                    <li><a href="{{ $base }}/notices{{ $q }}">{{ $ui('notices') }}</a></li>
                </ul>
            </div>
            <div>
                <h3>{{ $ui('contact_us') }}</h3>
                @if ($contact['address'] !== '')
                    <div class="line">@include('edu_website::icon', ['name' => 'pin'])<span class="lines">{{ $contact['address'] }}</span></div>
                @endif
                @if ($contact['phone'])
                    <div class="line">@include('edu_website::icon', ['name' => 'phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></div>
                @endif
                @if ($contact['email'])
                    <div class="line">@include('edu_website::icon', ['name' => 'mail'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></div>
                @endif
                @if ($contact['hours'] !== '')
                    <div class="line">@include('edu_website::icon', ['name' => 'clock'])<span>{{ $contact['hours'] }}</span></div>
                @endif
            </div>
        </div>
        @if ($powered_by)
            <p class="powered">{{ $ui('powered_by', ['name' => $powered_by]) }}</p>
        @endif
    </div>
</footer>

{{-- Pictures of a gallery, large (opened by the script; without it a picture opens on its own). --}}
<dialog id="lightbox" class="lightbox" aria-label="{{ $ui('gallery') }}">
    <button type="button" class="close" aria-label="{{ $ui('close') }}">@include('edu_website::icon', ['name' => 'close'])</button>
    <button type="button" class="prev" aria-label="{{ $ui('previous') }}">@include('edu_website::icon', ['name' => 'left'])</button>
    <figure><img alt=""><figcaption></figcaption></figure>
    <button type="button" class="next" aria-label="{{ $ui('next') }}">@include('edu_website::icon', ['name' => 'right'])</button>
    <span class="counter" aria-live="polite"></span>
</dialog>
{{-- Our own small script (no user data in it; allowed by its hash in the CSP). The page works without it. --}}
<script>@include('edu_website::site-js')</script>
</body>
</html>
