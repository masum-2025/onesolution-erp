<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Sites\SiteResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\MediaStore;
use Modules\EduWebsite\Services\Place;
use Modules\EduWebsite\Services\PublicSite;
use Modules\EduWebsite\Services\Sites;
use Symfony\Component\HttpFoundation\Response;

/**
 * A school's public website, for anyone (no sign-in): "/s/{slug}/…" on the
 * app's host, or "{slug}.{sites domain}/…" (ServeSiteByHost). Pages, the
 * notice board, a notice, and the site's pictures and files. Only a
 * published site of a company with the module on shows; otherwise one
 * "not available" page (no hint whether it exists). A signed preview link
 * shows the draft. Pages are kept for a few minutes; each publish or notice
 * change starts afresh.
 */
class PublicSiteController extends Controller
{
    private const UNAVAILABLE_CSS = 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;color:#3b404c;background:#f6f7f9;padding:16px;text-align:center}';

    public function __construct(
        private SiteResolver $resolver,
        private ModuleResolver $modules,
        private Place $place,
        private PublicSite $public,
        private MediaStore $media,
    ) {}

    public function byPath(Request $request, string $slug, ?string $path = null): Response
    {
        return $this->serve($request, strtolower($slug), trim((string) $path, '/'), '/s/'.strtolower($slug));
    }

    public function serve(Request $request, string $slug, string $path, string $base): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'edu-website:'.$request->ip(), (int) config('sites.per_minute'))) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $address = $this->resolver->find(Sites::MODULE, $slug);
        $company = $address?->organization;
        if ($company === null || ! $this->modules->isEnabled(Sites::MODULE, $company)) {
            return $this->unavailable();
        }
        $site = $this->place->query(Site::class, $company)->first();
        // Pictures and files have unguessable ids and also show in a draft's preview (whose links carry no signature).
        if ($site !== null && preg_match('#^(?:preview/)?media/([0-9a-z]{26})/(small|large|file)$#i', $path, $match)) {
            return $this->file($company, $site, $match[1], $match[2]);
        }
        // The site's own fonts (the same for every site; kept a year).
        if (preg_match('#^(?:preview/)?fonts/('.implode('|', PublicSite::FONTS).')\.woff2$#', $path, $match)) {
            return response()->file(module_path('EduWebsite', "resources/fonts/{$match[1]}.woff2"), [
                'Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        $preview = $path === 'preview' || str_starts_with($path, 'preview/');
        if ($preview) {
            if (! URL::hasValidSignature($request)) {
                return $this->unavailable();
            }
            $path = trim(substr($path, strlen('preview')), '/');
        }
        if ($site === null || (! $preview && $site->status !== Site::PUBLISHED)) {
            return $this->unavailable();
        }

        $locale = $this->public->locale($site, $request->query('lang'));
        $render = fn () => $this->page($company, $site, $path, $locale, $base, $preview);
        if ($preview) {
            return $this->guarded($render(), ['Cache-Control' => 'no-store']);
        }
        $cacheKey = "edu_website:page:{$site->getKey()}:{$site->cache_version}:{$locale}:".sha1($path);
        $html = Cache::get($cacheKey);
        if ($html === null) {
            $response = $render();
            if ($response->getStatusCode() !== 200) {
                return $this->guarded($response);
            }
            $html = (string) $response->getContent();
            Cache::put($cacheKey, $html, (int) config('sites.cache_seconds'));
        }

        return $this->guarded(response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), ['Cache-Control' => 'public, max-age=60']);
    }

    /**
     * A page's own strict policy: its inline stylesheet and our own small
     * script allowed by hash, pictures and fonts from the site only, no
     * other scripts, no framing by others.
     */
    private function guarded(Response $response, array $headers = []): Response
    {
        $html = (string) $response->getContent();
        $hashes = fn (string $tag) => preg_match_all("#<{$tag}>(.*?)</{$tag}>#s", $html, $found)
            ? implode(' ', array_map(fn (string $code) => "'sha256-".base64_encode(hash('sha256', $code, true))."'", array_unique($found[1])))
            : "'none'";
        // Our own stylesheet and script only (by hash; the same on every page of a site, so a kept page needs no nonce).
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; font-src 'self'; style-src {$hashes('style')}; script-src {$hashes('script')}; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function page(Organization $company, Site $site, string $path, string $locale, string $base, bool $preview): Response
    {
        $pages = $this->public->pages($company, $site, $preview);
        $frame = $this->public->frame($company, $site, $pages, $locale, $base.($preview ? '/preview' : ''));
        $frame['css'] = $this->public->css($frame['theme'], $base.($preview ? '/preview' : ''));
        if ($preview) {
            // Links inside a preview keep the signature, so the draft can be walked through.
            $frame['query'] = '?'.http_build_query(request()->only(['expires', 'signature', 'lang']));
        }

        if ($path === 'notices') {
            return response()->view('edu_website::notices', [...$frame, 'notices' => $this->public->notices($company, $site, 100, $base), 'page' => null]);
        }
        if (preg_match('#^notices/([0-9a-z]{26})$#i', $path, $match)) {
            $notice = $this->public->notice($company, $site, $match[1], $base);

            return $notice === null ? $this->notFound($frame) : response()->view('edu_website::notice', [...$frame, 'notice' => $notice, 'page' => null]);
        }

        $page = collect($pages)->first(fn ($page) => $path === '' ? $page['is_home'] : $page['slug'] === $path);
        if ($page === null) {
            return $this->notFound($frame);
        }

        return response()->view('edu_website::page', [...$frame, 'page' => $page, 'blocks' => $this->public->blocks($company, $site, $page, $base)]);
    }

    private function file(Organization $company, Site $site, string $id, string $size): Response
    {
        $media = $this->place->query(Media::class, $company)->where('site_id', $site->getKey())->whereKey($id)->first();
        $path = $media === null ? null : $this->media->path($media, $size);
        if ($path === null || ! Storage::disk($this->media->disk())->exists($path)) {
            return response('', 404);
        }

        return response()->file(Storage::disk($this->media->disk())->path($path), [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $media->name).($media->kind === 'pdf' ? '.pdf' : '.webp').'"',
        ]);
    }

    private function notFound(array $frame): Response
    {
        return response()->view('edu_website::missing', [...$frame, 'page' => null], 404);
    }

    private function unavailable(): Response
    {
        return $this->guarded(response()->view('edu_website::unavailable', ['css' => self::UNAVAILABLE_CSS], 404), ['Cache-Control' => 'no-store']);
    }
}
