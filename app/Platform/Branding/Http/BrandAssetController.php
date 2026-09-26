<?php

namespace App\Platform\Branding\Http;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\HostContext;
use App\Platform\Partners\Models\PartnerBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brand images (stored privately) and the per-brand PWA manifest. Brand
 * images are public by nature (they show on the sign-in page), but each is
 * served only at an address of its own partner or the platform.
 */
class BrandAssetController extends Controller
{
    private const TYPES = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function show(string $partner, string $kind, HostContext $host): Response
    {
        $column = PartnerBrand::ASSETS[$kind] ?? null;

        // Platform hosts serve every brand; a partner's (or client's) domain only its own.
        abort_if($column === null || ! ($host->isPlatform() || $host->partner()?->getKey() === $partner), 404);

        $path = PartnerBrand::query()->where('partner_id', $partner)->value($column);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        abort_if($path === null || ! isset(self::TYPES[$extension]) || ! Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => self::TYPES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            // The URL carries the brand version, so a changed image gets a new URL.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function manifest(BrandResolver $brands, HostContext $host): JsonResponse
    {
        $brand = $brands->for($host->partner());
        $icon = $brand['mark_url'] ?? $brand['favicon_url'];

        return response()->json([
            'name' => $brand['name'],
            'short_name' => mb_substr($brand['name'], 0, 12),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => $brand['primary_color'],
            'background_color' => '#FFFFFF',
            'icons' => $icon === null ? [] : [['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
