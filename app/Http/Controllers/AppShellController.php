<?php

namespace App\Http\Controllers;

use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\HostContext;
use Illuminate\Contracts\View\View;

/**
 * The single page that boots the browser app. It holds no user data: the
 * app asks /api/me after loading. The brand is the one of the address: a
 * partner's verified domain shows only that partner's brand.
 */
class AppShellController extends Controller
{
    public function __invoke(BrandResolver $brands, HostContext $host): View
    {
        $brand = $brands->for($host->partner(), $host->client());

        return view('app', [
            'brand' => $brand,
            'favicon' => $this->favicon($brand),
            'locales' => config('tenancy.supported_locales'),
            'defaultLocale' => config('tenancy.defaults.default_locale'),
        ]);
    }

    /**
     * The brand's favicon or mark, or else a small SVG icon in the brand color
     * with the brand's first letter, so every partner gets its own tab icon.
     *
     * @param  array{name: string, primary_color: string, mark_url: string|null, favicon_url: string|null}  $brand
     */
    private function favicon(array $brand): string
    {
        if (($brand['favicon_url'] ?? null) !== null || $brand['mark_url'] !== null) {
            return $brand['favicon_url'] ?? $brand['mark_url'];
        }

        $hex = ltrim($brand['primary_color'], '#');
        [$r, $g, $b] = array_map(fn (string $part) => hexdec($part) / 255, str_split($hex, 2));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        $text = $luminance > 0.6 ? '#111114' : '#FFFFFF';
        $letter = e(mb_strtoupper(mb_substr($brand['name'], 0, 1)));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="16" fill="#'.$hex.'"/>'
            .'<text x="32" y="43" font-family="Arial,sans-serif" font-size="32" font-weight="700" text-anchor="middle" fill="'.$text.'">'.$letter.'</text>'
            .'</svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }
}
