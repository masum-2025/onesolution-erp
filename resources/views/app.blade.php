<!DOCTYPE html>
<html lang="{{ $defaultLocale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <title>{{ $brand['name'] }}</title>
    <link rel="icon" href="{{ $favicon }}">
    @if ($brand['mark_url'])
        <link rel="apple-touch-icon" href="{{ $brand['mark_url'] }}">
    @endif
    <meta name="theme-color" content="{{ $brand['primary_color'] }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <style nonce="{{ Vite::cspNonce() }}">:root{--brand:{{ $brand['primary_color'] }}}</style>
    <script nonce="{{ Vite::cspNonce() }}">
        // Before first paint: theme and language from this browser's saved preferences.
        (function () {
            var d = document.documentElement, theme = 'system', locale = null, shell = null, vision = null, contrast = null;
            try { theme = localStorage.getItem('os.theme') || 'system'; locale = localStorage.getItem('os.locale'); shell = localStorage.getItem('os.shell'); vision = localStorage.getItem('os.vision'); contrast = localStorage.getItem('os.contrast'); } catch (e) {}
            // Look of the app as last applied here (the server's answer replaces it once loaded).
            d.setAttribute('data-shell', shell === 'light' ? 'light' : 'classic');
            if (vision === 'blue_orange') d.setAttribute('data-vision', vision);
            if (contrast === 'high') d.setAttribute('data-contrast', contrast);
            var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            d.classList.toggle('dark', dark);
            if (locale && @json($locales).indexOf(locale) !== -1) { d.lang = locale; d.dir = @json($rtlLocales).indexOf(locale) !== -1 ? 'rtl' : 'ltr'; }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app" data-brand="{{ json_encode($brand) }}" data-locales="{{ json_encode($locales) }}" data-languages="{{ json_encode($languages) }}" data-i18n="{{ json_encode($i18n) }}" data-default-locale="{{ $defaultLocale }}" data-signup="{{ json_encode($signup) }}"></div>
    <noscript>{{ __('tenancy.messages.javascript_required') }}</noscript>
</body>
</html>
