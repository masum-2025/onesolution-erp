@php
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($brand['primary_color'] ?? '')) ? $brand['primary_color'] : '#2B4C9B';
    $logo = $brand['logo_url'] ?? null;
    // The same page in another language (a form shown again after sending links to its job page).
    $path = preg_replace('#/apply$#', '', '/'.ltrim(request()->path(), '/'));
    $here = fn (string $lang) => $path.'?'.http_build_query(['lang' => $lang]);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $robots ?? 'index, follow' }}">
    <title>{{ $title ?? $ui('title', ['company' => $company]) }}</title>
    <style>
        :root { --brand: {{ $color }}; --fg: #16181d; --muted: #5b6270; --line: #e3e6ec; --bg: #f6f7fb; --card: #ffffff; --bad: #b42318; --ok: #067647; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Bengali", "Hind Siliguri", sans-serif; color: var(--fg); background: var(--bg); line-height: 1.55; }
        a { color: var(--brand); }
        .wrap { max-inline-size: 880px; margin-inline: auto; padding-inline: 16px; }
        header.top { background: var(--card); border-block-end: 1px solid var(--line); }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-block: 14px; }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--fg); font-weight: 700; }
        .brand img { block-size: 32px; inline-size: auto; }
        .langs { display: flex; gap: 6px; font-size: 13px; }
        .langs a { padding: 4px 10px; border-radius: 999px; text-decoration: none; color: var(--muted); border: 1px solid var(--line); }
        .langs a[aria-current="true"] { background: var(--brand); color: #fff; border-color: var(--brand); }
        .hero { background: linear-gradient(135deg, var(--brand), #10172a); color: #fff; padding-block: 40px 56px; }
        .hero h1 { margin: 0 0 6px; font-size: clamp(26px, 5vw, 38px); line-height: 1.15; }
        .hero p { margin: 0; opacity: .88; }
        .hero a { color: #fff; }
        .hero .meta { color: rgba(255, 255, 255, .88); margin-block-start: 8px; }
        .actions { margin-block-start: 18px; }
        main { padding-block: 24px 48px; }
        .lift { margin-block-start: -32px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 20px; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        .jobs { display: grid; gap: 12px; padding: 0; margin: 0; list-style: none; }
        .job { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; text-decoration: none; color: inherit; }
        .job:hover { border-color: var(--brand); }
        .job h2 { margin: 0 0 4px; font-size: 18px; }
        .meta { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--muted); font-size: 14px; }
        .chip { display: inline-block; padding: 2px 10px; border-radius: 999px; background: color-mix(in srgb, var(--brand) 12%, white); color: var(--brand); font-size: 13px; font-weight: 600; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 10px; border: 0; background: var(--brand); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn[disabled] { opacity: .6; cursor: default; }
        .text { white-space: pre-line; }
        h2.section { font-size: 18px; margin: 28px 0 12px; }
        form .fields { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        label.field { display: grid; gap: 6px; font-size: 14px; font-weight: 600; }
        label.field.wide { grid-column: 1 / -1; }
        label.field em { font-weight: 400; color: var(--muted); font-style: normal; }
        input, textarea { font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--fg); inline-size: 100%; }
        input:focus, textarea:focus { outline: 2px solid var(--brand); outline-offset: 1px; }
        input[aria-invalid="true"], textarea[aria-invalid="true"] { border-color: var(--bad); }
        .err { color: var(--bad); font-weight: 400; }
        .alert { padding: 12px 14px; border-radius: 10px; background: #fef3f2; color: var(--bad); margin: 0 0 14px; }
        .done { text-align: center; padding-block: 28px; }
        .done .tick { inline-size: 56px; block-size: 56px; border-radius: 50%; margin: 0 auto 12px; display: grid; place-items: center; background: #ecfdf3; color: var(--ok); font-size: 28px; }
        .consent { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; font-weight: 400; }
        .consent input { inline-size: auto; margin-block-start: 4px; }
        .hp { position: absolute; inset-inline-start: -10000px; inline-size: 1px; block-size: 1px; overflow: hidden; }
        .muted { color: var(--muted); font-size: 14px; }
        footer { color: var(--muted); font-size: 13px; padding-block: 24px; text-align: center; }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="/careers/{{ $slug }}?lang={{ $locale }}">
                @if ($logo)<img src="{{ $logo }}" alt="">@endif
                <span>{{ $company }}</span>
            </a>
            @if (count($languages) > 1)
                <nav class="langs" aria-label="{{ $ui('language') }}">
                    @foreach ($languages as $lang)
                        <a href="{{ $here($lang) }}" lang="{{ $lang }}" @if ($lang === $locale) aria-current="true" @endif>{{ $lang === 'bn' ? 'বাংলা' : strtoupper($lang) }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </header>
    @yield('content')
    <footer>{{ $ui('footer', ['company' => $company, 'product' => $brand['name'] ?? '']) }}</footer>
</body>
</html>
