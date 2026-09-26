<?php

namespace App\Platform\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Shows a rendered email preview inside the template editor. The page has
 * its own policy: inline styles (as in real emails) but no scripts, no
 * requests except images, framed only by the app itself. Only the person who
 * made the preview can open it, for a few minutes.
 */
class TemplatePreviewController extends Controller
{
    public const TTL_MINUTES = 10;

    public function show(Request $request, string $preview): Response
    {
        $stored = Cache::get("template-preview:{$preview}");

        if (! is_array($stored) || $stored['user'] !== $request->user()?->getAuthIdentifier()) {
            abort(404);
        }

        return response($stored['html'], 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The frame is sandboxed (opaque origin), so the editor's origin is named, not 'self'.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src https: http: data:; base-uri 'none'; form-action 'none'; frame-ancestors {$request->getSchemeAndHttpHost()}",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
