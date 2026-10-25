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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\Enquiries;
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
    /** Enquiries one visitor (address) may send an hour. */
    private const ENQUIRIES_PER_HOUR = 5;

    /** Certificate checks one address (the sites server) may make a minute. */
    private const TLS_CHECKS_PER_MINUTE = 600;

    private const UNAVAILABLE_CSS = 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;color:#3b404c;background:#f6f7f9;padding:16px;text-align:center}';

    public function __construct(
        private SiteResolver $resolver,
        private ModuleResolver $modules,
        private Place $place,
        private PublicSite $public,
        private MediaStore $media,
        private Enquiries $enquiries,
    ) {}

    public function byPath(Request $request, string $slug, ?string $path = null): Response
    {
        return $this->serve($request, strtolower($slug), trim((string) $path, '/'), '/s/'.strtolower($slug));
    }

    /**
     * For the sites server's on-demand certificates: 200 when the host is a site's address on the
     * sites domain or a site's proven own domain, else 404 (no certificate for names nobody owns here).
     */
    public function tls(Request $request): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'edu-website-tls:'.$request->ip(), self::TLS_CHECKS_PER_MINUTE)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
        $host = strtolower(trim((string) $request->query('domain', '')));
        $slug = $this->resolver->slugFromHost($host);
        $known = $slug !== null ? $this->resolver->find(Sites::MODULE, $slug) !== null : ($host !== '' && $this->resolver->slugForDomain($host) !== null);

        return response('', $known ? 200 : 404, ['Cache-Control' => 'no-store']);
    }

    public function enquireByPath(Request $request, string $slug): Response
    {
        return $this->enquire($request, strtolower($slug), '/s/'.strtolower($slug));
    }

    /**
     * An admission enquiry sent from a published page's form. Robots (the hidden field filled, or sent
     * within seconds) are told it went and nothing is kept; a form that is not this site's or too old,
     * or wrong details, show the page again with what to fix; a few an hour per visitor.
     */
    public function enquire(Request $request, string $slug, string $base): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'edu-website-enquiry:'.$request->ip(), self::ENQUIRIES_PER_HOUR)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        $address = $this->resolver->find(Sites::MODULE, $slug);
        $company = $address?->organization;
        $site = $company === null || ! $this->modules->isEnabled(Sites::MODULE, $company) ? null : $this->place->query(Site::class, $company)->first();
        $back = (string) $request->input('back', '');
        $page = $site?->status !== Site::PUBLISHED ? null
            : collect($site->published['pages'] ?? [])->first(fn ($page) => $back === '' ? $page['is_home'] : $page['slug'] === $back);
        if ($page === null) {
            return $this->unavailable();
        }
        RateLimiter::hit($key, 3600);

        $locale = $this->public->locale($site, $request->input('lang'));
        $path = $page['is_home'] ? '' : $page['slug'];
        $ui = fn (string $text) => __("edu_website::public.{$text}", [], $locale);
        $old = array_map('strval', $request->only(['student_name', 'guardian_name', 'phone', 'level_id', 'message']));
        $again = fn (array $errors, int $status) => $this->guarded(
            $this->page($company, $site, $path, $locale, $base, false, $this->resolver->primaryUrl($address), ['errors' => $errors, 'old' => $old])->setStatusCode($status),
            ['Cache-Control' => 'no-store'],
        );
        $sent = fn () => redirect()->to(($base === '' ? '' : $base).'/'.$path.'?'.http_build_query(['lang' => $locale, 'enquiry' => 'sent']).'#enquiry', 303);

        $check = $this->enquiries->check($site, $request->input('t'));
        if (filled($request->input('website')) || $check === 'fast') {
            return $sent();
        }
        if ($check === 'bad') {
            return $again(['form' => [$ui('enquiry_expired')]], 422);
        }
        $validator = Validator::make($old, [
            'student_name' => ['required', 'string', 'max:100'],
            'guardian_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'level_id' => ['nullable', 'string', 'size:26'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], ['required' => $ui('enquiry_required'), 'max' => $ui('enquiry_too_long'), 'size' => $ui('enquiry_bad_level')]);
        if ($validator->fails()) {
            return $again($validator->errors()->toArray(), 422);
        }
        try {
            $this->enquiries->receive($company, $site, $validator->validated(), $locale);
        } catch (ValidationException $invalid) {
            return $again($invalid->errors(), 422);
        } catch (WebsiteException) {
            return $again(['form' => [__('edu_website::website.errors.too_many_enquiries', [], $locale)]], 429);
        }

        return $sent();
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

        // One address for search engines: the site's own domain once proven, else its sites-domain address.
        $origin = $this->resolver->primaryUrl($address);
        if (! $preview && $path === 'robots.txt') {
            return response("User-agent: *
Allow: /
Sitemap: {$origin}/sitemap.xml
", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
        }
        if (! $preview && $path === 'sitemap.xml') {
            return response($this->public->sitemap($company, $site, $origin), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
        }

        $locale = $this->public->locale($site, $request->query('lang'));
        // Back from sending an enquiry: the thanks shows (made afresh, never kept).
        $state = $request->query('enquiry') === 'sent' ? ['sent' => true] : [];
        $render = fn () => $this->page($company, $site, $path, $locale, $base, $preview, $origin, $state);
        if ($preview || $state !== []) {
            return $this->guarded($render(), ['Cache-Control' => 'no-store']);
        }
        // The base is part of the key: links differ between "/s/{slug}" and the site's own host.
        $cacheKey = "edu_website:page:{$site->getKey()}:{$site->cache_version}:{$locale}:".sha1($base.'|'.$path);
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
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; font-src 'self'; style-src {$hashes('style')}; script-src {$hashes('script')}; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @param  array{sent?: bool, errors?: array<string, list<string>>, old?: array<string, string>}  $enquiry  The enquiry form's state.
     */
    private function page(Organization $company, Site $site, string $path, string $locale, string $base, bool $preview, string $origin, array $enquiry = []): Response
    {
        $pages = $this->public->pages($company, $site, $preview);
        $frame = $this->public->frame($company, $site, $pages, $locale, $base.($preview ? '/preview' : ''));
        $frame['css'] = $this->public->css($frame['theme'], $base.($preview ? '/preview' : ''));
        if ($preview) {
            // Links inside a preview keep the signature, so the draft can be walked through.
            $frame['query'] = '?'.http_build_query(request()->only(['expires', 'signature', 'lang']));
        }

        // Search and sharing details (none for a preview, which is never indexed).
        $seo = fn (?array $page, ?string $description = null) => $preview ? null : $this->public->seo($site, $origin, $path, $locale, $page, $description);

        if ($path === 'notices') {
            return response()->view('edu_website::notices', [...$frame, 'notices' => $this->public->notices($company, $site, 100, $base), 'page' => null, 'seo' => $seo(null)]);
        }
        if (preg_match('#^notices/([0-9a-z]{26})$#i', $path, $match)) {
            $notice = $this->public->notice($company, $site, $match[1], $base);

            return $notice === null ? $this->notFound($frame) : response()->view('edu_website::notice', [
                ...$frame, 'notice' => $notice, 'page' => null,
                'seo' => $seo(null, mb_substr($frame['text']($notice['body']), 0, 160) ?: null),
            ]);
        }

        $page = collect($pages)->first(fn ($page) => $path === '' ? $page['is_home'] : $page['slug'] === $path);
        if ($page === null) {
            return $this->notFound($frame);
        }

        return response()->view('edu_website::page', [...$frame, 'page' => $page, 'blocks' => $this->public->blocks($company, $site, $page, $base), 'seo' => $seo($page), 'enquiry' => $enquiry]);
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
