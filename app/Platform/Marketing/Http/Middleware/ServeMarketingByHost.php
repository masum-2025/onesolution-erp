<?php

namespace App\Platform\Marketing\Http\Middleware;

use App\Platform\Marketing\Http\Controllers\PublicWebsiteController;
use App\Platform\Marketing\Models\Site;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Tenancy\Models\Partner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company websites answer their hosts here, before anything of the app runs:
 * - the platform's marketing hosts (config marketing.hosts): the house
 *   partner's site;
 * - a partner's verified domain that serves "website" (MKT-3c): that
 *   partner's site, never another's and never the app.
 * Reading only (GET, HEAD), except the contact form's post. Every other host
 * passes through untouched (ResolveHost decides).
 */
class ServeMarketingByHost
{
    public function __construct(private HostResolver $hosts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $partnerId = $this->partnerFor(strtolower($request->getHost()), $request);
        if ($partnerId === null) {
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
        $site = $partnerId === false ? null : Site::query()->where('partner_id', $partnerId)->first();
        if ($site === null) {
            return response()->view('marketing.unavailable', [], 404)->header('Cache-Control', 'no-store');
        }

        if ($contact) {
            return app(PublicWebsiteController::class)->contact($request, $site);
        }

        return app(PublicWebsiteController::class)->serve($request, $site, $request->path() === '/' ? '' : $request->path());
    }

    /**
     * Whose website this host shows: a partner id, false for a website host
     * without a site (no house partner yet), null when it is not a website host.
     */
    private function partnerFor(string $host, Request $request): string|false|null
    {
        $marketing = array_filter(array_map(fn ($item) => strtolower(trim($item)), explode(',', (string) config('marketing.hosts'))));
        if (in_array($host, $marketing, true)) {
            return Partner::query()->where('is_house', true)->value('id') ?? false;
        }
        // The app's own hosts and the health check never show a website.
        if ($this->hosts->isPlatformHost($host) || $request->is('up')) {
            return null;
        }
        $domain = $this->hosts->activeDomain($host);

        return $domain !== null && $domain->servesWebsite() && $domain->organization_id === null ? $domain->partner_id : null;
    }
}
