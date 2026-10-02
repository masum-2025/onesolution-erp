<?php

/*
|--------------------------------------------------------------------------
| Branding and addresses
|--------------------------------------------------------------------------
|
| house: the platform's own brand (house partner, platform hosts). White-label
| partners have their own brand in `partner_brands` (Phase 5B); these values
| are never shown on a partner's domain.
|
| logo_url: full logo (mark + name) for light backgrounds.
| mark_url: the symbol alone (sidebar, favicon), square, transparent.
| Both must be paths on this site (the page's CSP allows no other origin).
|
*/

return [

    'house' => [
        'name' => env('HOUSE_PARTNER_NAME', 'One Solutions'),
        'primary_color' => env('BRAND_PRIMARY_COLOR', '#2B4C9B'),
        'support_email' => env('BRAND_SUPPORT_EMAIL'),
        'logo_url' => env('BRAND_LOGO_URL', '/brand/house/logo.png'),
        'mark_url' => env('BRAND_MARK_URL', '/brand/house/mark.png'),
        // The logo's colors as a strip under the header, and its white level with
        // it under the sidebar's brand row. Empty = no band.
        'band_colors' => ['#2B4C9B', '#DD5144', '#23A562'],
        'side_band_color' => '#FFFFFF',
        'tagline' => [
            'en' => 'the symbol of freedom',
            'bn' => 'স্বাধীনতার প্রতীক',
        ],
    ],

    // Host names of the platform itself (comma separated, no port). The host of
    // APP_URL is always included. Every other host must be a verified partner domain.
    'platform_hosts' => env('PLATFORM_HOSTS', 'localhost,127.0.0.1'),

    // The "Powered by" badge names the platform.
    'powered_by' => env('POWERED_BY_NAME', 'One Solutions'),

    // Fonts a partner may choose (bundled with the app; no external font hosts).
    'fonts' => [
        'inter' => "'Inter Variable', 'Hind Siliguri', ui-sans-serif, system-ui, sans-serif",
        'system' => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', 'Hind Siliguri', sans-serif",
        'hind_siliguri' => "'Hind Siliguri', 'Inter Variable', ui-sans-serif, system-ui, sans-serif",
    ],

    // Brand images: raster only (SVG can carry scripts), size in kilobytes.
    'asset_max_kb' => 512,

    // Caddy on-demand TLS "ask" endpoint secret (GET /internal/tls/ask?domain=...&token=...).
    'tls_ask_token' => env('TLS_ASK_TOKEN'),

];
