{{-- A company website page: server-built, its own stylesheet and script only (see PublicWebsiteController::guarded). --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" class="tpl-{{ $template }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] ?? $brand['name'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if ($preview || ($seo['noindex'] ?? false))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @isset($seo)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        @foreach ($seo['alternates'] as $code => $href)
            <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
        @endforeach
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brand['name'] }}">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        <meta property="og:locale" content="{{ $locale }}">
        @if (($seo['description'] ?? '') !== '')
            <meta property="og:description" content="{{ $seo['description'] }}">
        @endif
        @if ($seo['image'])
            <meta property="og:image" content="{{ $seo['image'] }}">
            <meta name="twitter:card" content="summary_large_image">
        @endif
        {{-- Structured data: JSON with < > & ' " written as \u00XX (PublicWebsite::seo), so escaping changes nothing and it cannot leave its element. --}}
        <script type="application/ld+json">{{ $seo['json_ld'] }}</script>
    @endisset
    @foreach ($verification as $name => $value)
        <meta name="{{ $name }}" content="{{ $value }}">
    @endforeach
    @if ($brand['mark'])
        <link rel="icon" href="{{ $brand['mark'] }}">
    @endif
    <link rel="preload" href="{{ $assets['base'] }}/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ $assets['base'] }}/site.css?v={{ $assets['version'] }}">
    <script src="{{ $assets['base'] }}/site.js?v={{ $assets['version'] }}" defer></script>
</head>
<body>
<a class="skip" href="#main">{{ $ui('skip') }}</a>

@if ($notice)
    <div class="notice"><div class="wrap">
        @if ($tx($notice['tag'] ?? null) !== '')<span class="nt">{{ $tx($notice['tag']) }}</span>@endif
        {{ $tx($notice['text'] ?? null) }}
        @if ($tx($notice['link_label'] ?? null) !== '')<a href="{{ $link($notice['url'] ?? null) }}">{{ $tx($notice['link_label']) }} →</a>@endif
        @if ($tx($notice['extra'] ?? null) !== '')<span class="ofr"><span class="nsep">·</span>{{ $tx($notice['extra']) }}</span>@endif
    </div></div>
@endif

<header class="top">
    <div class="band"></div>
    <nav class="wrap nav" aria-label="{{ $ui('menu') }}">
        <a class="brand" href="{{ $home }}">
            @if ($brand['mark'])<img src="{{ $brand['mark'] }}" alt="" width="26" height="26">@endif
            <span>{{ $brand['name'] }}</span>
        </a>
        <div class="links">
            @foreach ($header['menu'] ?? [] as $item)
                <a href="{{ $link($item['url']) }}">{{ $tx($item['label']) }}</a>
            @endforeach
        </div>
        <div class="end">
            @if (count($languages) > 1)
                @foreach ($languages as $language)
                    @unless ($language['current'])
                        <a class="lang" href="{{ $language['url'] }}" hreflang="{{ $language['code'] }}" lang="{{ $language['code'] }}">{{ $language['label'] }}</a>
                    @endunless
                @endforeach
            @endif
            @if ($tx($header['signin_label'] ?? null) !== '')
                <a class="si" href="{{ $link($header['signin_url'] ?? null) }}">{{ $tx($header['signin_label']) }}</a>
            @endif
            @if ($tx($header['cta_label'] ?? null) !== '')
                <a class="btn btn-p" href="{{ $link($header['cta_url'] ?? null) }}">{{ $tx($header['cta_label']) }}</a>
            @endif
            <details class="mnav">
                <summary class="btn btn-g" aria-label="{{ $ui('menu') }}">☰</summary>
                <div class="menu-list">
                    @foreach ($header['menu'] ?? [] as $item)
                        <a href="{{ $link($item['url']) }}">{{ $tx($item['label']) }}</a>
                    @endforeach
                    @foreach ($languages as $language)
                        @unless ($language['current'])
                            <a href="{{ $language['url'] }}" lang="{{ $language['code'] }}">{{ $language['label'] }}</a>
                        @endunless
                    @endforeach
                </div>
            </details>
        </div>
    </nav>
</header>

<main id="main">
    @yield('content')
</main>

<footer>
    <div class="wrap fgrid">
        <div>
            <a class="brand" href="{{ $home }}">@if ($brand['mark'])<img src="{{ $brand['mark'] }}" alt="" width="26" height="26">@endif<span>{{ $brand['name'] }}</span></a>
            @if ($tx($footer['about'] ?? null) !== '')<p class="about">{{ $tx($footer['about']) }}</p>@endif
            <div class="foot-contact">
                @if (filled($contact['phone'] ?? null))<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@endif
                @if (filled($contact['email'] ?? null))<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@endif
                @if ($tx($contact['address'] ?? null) !== '')<span>{{ $tx($contact['address']) }}</span>@endif
            </div>
            @if ($social !== [])
                <div class="foot-social">
                    @foreach ($social as $network => $url)
                        <a href="{{ $url }}" rel="noopener" target="_blank">{{ $ui('social_'.$network) }}</a>
                    @endforeach
                </div>
            @endif
        </div>
        @foreach ($footer['columns'] ?? [] as $column)
            <div>
                <h4>{{ $tx($column['title']) }}</h4>
                <ul class="plain">
                    @foreach ($column['links'] as $item)
                        <li><a href="{{ $link($item['url']) }}">{{ $tx($item['label']) }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
    <div class="wrap fbot">
        <span>{{ $tx($footer['copyright'] ?? null) ?: $ui('copyright', ['year' => $number($year), 'name' => $brand['name']]) }}</span>
        <span>
            @foreach ($languages as $language)
                <a href="{{ $language['url'] }}" lang="{{ $language['code'] }}">{{ $language['label'] }}</a>@if (! $loop->last) · @endif
            @endforeach
        </span>
    </div>
</footer>
</body>
</html>
