<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; min-block-size: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Bengali", sans-serif; background: #f6f7fb; color: #16181d; }
        main { inline-size: min(100% - 32px, 420px); background: #fff; border: 1px solid #e3e6ee; border-radius: 16px; padding: 24px; box-sizing: border-box; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #5b6270; font-size: 14px; margin: 0 0 16px; }
        dl { display: grid; grid-template-columns: 1fr auto; gap: 8px 16px; margin: 0 0 20px; font-size: 15px; }
        dt { color: #5b6270; } dd { margin: 0; text-align: end; font-variant-numeric: tabular-nums; }
        .now { font-weight: 700; font-size: 18px; color: #16181d; }
        button { inline-size: 100%; padding: 14px; border: 0; border-radius: 12px; background: #2B4C9B; color: #fff; font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; }
        .note { border-radius: 12px; padding: 12px 14px; margin: 0 0 16px; font-size: 14px; }
        .ok { background: #e7f6ee; color: #0f5132; } .warn { background: #fff4e0; color: #7a4b00; } .bad { background: #fde8e8; color: #8a1c1c; }
    </style>
</head>
<body>
    <main>@yield('body')</main>
</body>
</html>
