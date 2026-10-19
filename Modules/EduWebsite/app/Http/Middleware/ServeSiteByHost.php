<?php

namespace Modules\EduWebsite\Http\Middleware;

use App\Platform\Sites\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Modules\EduWebsite\Http\Controllers\PublicSiteController;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request for "{slug}.{sites domain}" is a school's public website: it is
 * answered here (GET only) and never reaches the app. Every other host
 * passes through untouched.
 */
class ServeSiteByHost
{
    public function __construct(private SiteResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->resolver->slugFromHost($request->getHost());
        if ($slug === null) {
            return $next($request);
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response('', 405, ['Allow' => 'GET, HEAD']);
        }

        return app(PublicSiteController::class)->serve($request, $slug, trim($request->path(), '/'), '');
    }
}
