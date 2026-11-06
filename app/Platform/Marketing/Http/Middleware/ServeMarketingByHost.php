<?php

namespace App\Platform\Marketing\Http\Middleware;

use App\Platform\Marketing\Http\Controllers\PublicWebsiteController;
use App\Platform\Marketing\Models\Site;
use App\Platform\Tenancy\Models\Partner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request for one of the platform's marketing hosts (config
 * marketing.hosts) is the platform's own company website (the house
 * partner's): answered here (GET and HEAD only) and never reaching the app.
 * Every other host passes through untouched.
 */
class ServeMarketingByHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = array_filter(array_map(fn ($host) => strtolower(trim($host)), explode(',', (string) config('marketing.hosts'))));
        if ($hosts === [] || ! in_array(strtolower($request->getHost()), $hosts, true)) {
            return $next($request);
        }
        // Reading only, except the contact form's post (MKT-3b).
        $contact = $request->isMethod('POST') && trim($request->path(), '/') === 'contact';
        if (! $contact && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response('', 405, ['Allow' => 'GET, HEAD']);
        }
        // A plain form post only (no files, no JSON).
        if ($contact && ! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/x-www-form-urlencoded')) {
            return response('', 415);
        }
        $house = Partner::query()->where('is_house', true)->value('id');
        $site = $house === null ? null : Site::query()->where('partner_id', $house)->first();
        if ($site === null) {
            return response()->view('marketing.unavailable', [], 404)->header('Cache-Control', 'no-store');
        }

        if ($contact) {
            return app(PublicWebsiteController::class)->contact($request, $site);
        }

        return app(PublicWebsiteController::class)->serve($request, $site, $request->path() === '/' ? '' : $request->path());
    }
}
