<!DOCTYPE html>
<html lang="{{ $defaultLocale }}" dir="ltr">
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
    <style nonce="{{ Vite::cspNonce() }}">:root{--brand:{{ $brand['primary_color'] }}}</style>
    <script nonce="{{ Vite::cspNonce() }}">
        // Before first paint: theme and language from this browser's saved preferences.
        (function () {
            var d = document.documentElement, theme = 'system', locale = null;
            try { theme = localStorage.getItem('os.theme') || 'system'; locale = localStorage.getItem('os.locale'); } catch (e) {}
            var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            d.classList.toggle('dark', dark);
            if (locale && @json($locales).indexOf(locale) !== -1) { d.lang = locale; }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app" data-brand="{{ json_encode($brand) }}" data-locales="{{ json_encode($locales) }}" data-default-locale="{{ $defaultLocale }}"></div>
    <noscript>{{ __('tenancy.messages.javascript_required') }}</noscript>
</body>
</html>
