<?php

namespace App\Http\Controllers;

use App\Platform\Branding\BrandResolver;
use Illuminate\Contracts\View\View;

/**
 * The single page that boots the browser app. It holds no user data: the
 * app asks /api/me after loading. The brand here is the house brand until
 * Phase 5B resolves the partner from the domain.
 */
class AppShellController extends Controller
{
    public function __invoke(BrandResolver $brands): View
    {
        $brand = $brands->for();

        return view('app', [
            'brand' => $brand,
            'favicon' => $this->favicon($brand),
            'locales' => config('tenancy.supported_locales'),
            'defaultLocale' => config('tenancy.defaults.default_locale'),
        ]);
    }

    /**
     * A small SVG icon in the brand color with the brand's first letter,
     * so every partner gets its own tab icon without uploading one.
     *
     * @param  array{name: string, primary_color: string}  $brand
     */
    private function favicon(array $brand): string
    {
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
