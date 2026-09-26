<?php

namespace App\Platform\Branding\Http;

use App\Http\Controllers\Controller;
use App\Platform\Branding\Models\ClientBrand;
use App\Platform\Partners\HostContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client's logo (stored privately). Public by nature (it shows on the
 * client's sign-in page), but served only at the platform address or an
 * address of the client's own partner (on a client domain: that client only).
 */
class ClientBrandAssetController extends Controller
{
    private const TYPES = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function show(string $organization, HostContext $host): Response
    {
        $root = Organization::query()->whereNull('parent_id')->whereKey($organization)->first();
        abort_if($root === null || ! ($host->isPlatform() || $host->allowsOrganization($root)), 404);

        $path = ClientBrand::query()->where('organization_id', $root->getKey())->value('logo_path');
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        abort_if($path === null || ! isset(self::TYPES[$extension]) || ! Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => self::TYPES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
