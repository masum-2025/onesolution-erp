<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $ui('missing') }}</title>
    <style>
        body { margin: 0; min-block-size: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Bengali", sans-serif; background: #f6f7fb; color: #16181d; }
        main { text-align: center; padding: 24px; max-inline-size: 420px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #5b6270; }
        a { color: #2B4C9B; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $ui('missing') }}</h1>
        <p>{{ $ui('missing_text') }}</p>
        @if ($back)<p><a href="{{ $back }}?lang={{ $locale }}">{{ $ui('all_jobs') }}</a></p>@endif
    </main>
</body>
</html>
