<?php

namespace Modules\EduWebsite\Http\Middleware;

use App\Platform\Partners\Services\HostResolver;
use App\Platform\Sites\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Modules\EduWebsite\Http\Controllers\PublicSiteController;
use Modules\EduWebsite\Services\Sites;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request for "{slug}.{sites domain}" or for a site's verified own domain
 * is a school's public website: it is answered here (GET, and POST of an
 * admission enquiry only) and never
 * reaches the app. A site that has its own domain sends visitors of its
 * sites-domain address there (permanently). The app's own hosts and every
 * other host pass through untouched.
 */
class ServeSiteByHost
{
    public function __construct(private SiteResolver $resolver, private HostResolver $hosts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $slug = $this->resolver->slugFromHost($host);
        $own = false;
        if ($slug === null) {
            if ($this->hosts->isPlatformHost($host)) {
                return $next($request);
            }
            $slug = $this->resolver->slugForDomain($host);
            if ($slug === null) {
                return $next($request);
            }
            $own = true;
        }
        $path = trim($request->path(), '/');
        // An admission enquiry is the one thing sent to a site.
        if ($request->isMethod('POST') && $path === 'enquiry') {
            return app(PublicSiteController::class)->enquire($request, $slug, '');
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response('', 405, ['Allow' => 'GET, HEAD']);
        }
        if (! $own) {
            $address = $this->resolver->find(Sites::MODULE, $slug);
            if ($address?->domain !== null && $address->domain_verified_at !== null) {
                return redirect()->away('https://'.$address->domain.$request->getRequestUri(), 301);
            }
        }

        return app(PublicSiteController::class)->serve($request, $slug, $path, '');
    }
}
