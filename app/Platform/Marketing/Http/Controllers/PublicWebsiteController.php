<?php

namespace App\Platform\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Marketing\Models\Media;
use App\Platform\Marketing\Models\Redirect;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Services\Leads;
use App\Platform\Marketing\Services\MediaLibrary;
use App\Platform\Marketing\Services\PublicWebsite;
use App\Platform\Marketing\Services\Websites;
use App\Platform\Partners\Models\PartnerBrand;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company website, for anyone (no sign-in): on its own host
 * (ServeMarketingByHost) the published snapshot; at "/website-preview/{site}"
 * on the app's host, with a signed link, the drafts (never indexed). Pages
 * are kept a few minutes; each publish starts afresh. The page's policy
 * allows only the site's own stylesheet, script, pictures and fonts.
 */
class PublicWebsiteController extends Controller
{
    private const FONTS = ['inter-latin', 'hind-bengali-400', 'hind-bengali-600'];

    private const IMAGE_TYPES = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function __construct(private PublicWebsite $public, private Websites $websites, private MediaLibrary $media, private Leads $leads) {}

    /** The site on its own host. */
    public function serve(Request $request, Site $site, string $path): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $path = trim($path, '/');
        if ($asset = $this->asset($site, $path, $site->published ?? null, (string) $site->cache_version)) {
            return $asset;
        }
        if ($site->status !== Site::PUBLISHED || $site->published === null) {
            return $this->unavailable();
        }
        $snapshot = $site->published;
        $origin = rtrim((string) config('marketing.url'), '/') ?: $request->getSchemeAndHttpHost();

        if ($path === 'robots.txt') {
            return response("User-agent: *\nAllow: /\nSitemap: {$origin}/sitemap.xml\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
        }
        if ($path === 'sitemap.xml') {
            return response($this->public->sitemap($snapshot, $origin), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
        }

        [$locale, $slug] = $this->public->split($snapshot, $path);
        $page = $this->public->page($snapshot, $slug);
        if ($page === null) {
            $redirect = Redirect::query()->where('site_id', $site->getKey())->where('from_path', '/'.$slug)->first();
            if ($redirect !== null) {
                $redirect->increment('hits');
                $prefix = $locale === $snapshot['default_locale'] ? '' : '/'.$locale;

                return redirect()->to(str_starts_with($redirect->to_path, '/') ? $prefix.$redirect->to_path : $redirect->to_path, 301);
            }
        }

        // The day is part of the key: an offer or a notice bar ends at midnight.
        $key = "marketing:page:{$site->getKey()}:{$site->cache_version}:{$locale}:".sha1($slug).':'.CarbonImmutable::now()->toDateString();
        $cached = $page === null ? null : Cache::get($key);
        if ($cached === null) {
            $html = view($page === null ? 'marketing.missing' : 'marketing.page', $this->public->view(
                $site, $snapshot, $page, $locale, $origin, '', null, ['base' => '/assets', 'version' => (string) $site->cache_version]
            ))->render();
            if ($page === null) {
                return $this->guarded(response($html, 404), ['Cache-Control' => 'no-store']);
            }
            Cache::put($key, $html, (int) config('marketing.cache_seconds'));
            $cached = $html;
        }

        return $this->guarded(response($cached, 200, ['Content-Type' => 'text/html; charset=UTF-8']), ['Cache-Control' => 'public, max-age=60']);
    }

    /**
     * A visitor's message from the contact form (POST /contact on the site's host). Answered with
     * the page again (never kept): a thank-you, or their answers with what to fix. A bot's post
     * (the hidden field filled) is thanked and dropped; a stale or forged stamp asks to send again.
     */
    public function contact(Request $request, Site $site): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        if ($site->status !== Site::PUBLISHED || $site->published === null) {
            return $this->unavailable();
        }
        $snapshot = $site->published;
        $slug = trim((string) $request->input('page', ''), '/');
        $page = $this->public->page($snapshot, $slug);
        $blockId = (string) $request->input('block', '');
        $block = $page === null ? null : collect($page['blocks'])->first(fn ($item) => $item['id'] === $blockId && $item['type'] === 'contact_form');
        if ($block === null) {
            return $this->unavailable();
        }
        $lang = (string) $request->input('lang', '');
        $locale = in_array($lang, $snapshot['locales'], true) ? $lang : $snapshot['default_locale'];
        $form = ['block' => $blockId, 'state' => 'sent', 'old' => [], 'errors' => []];
        $status = 200;
        // Only texts (anything else counts as empty), at most what any field takes.
        $input = array_map(fn ($value) => is_string($value) ? mb_substr($value, 0, 2001) : '', $request->only(['name', 'phone', 'email', 'company', 'message']));

        if (trim((string) $request->input(Leads::TRAP, '')) !== '') {
            // A bot: thanked, nothing kept.
        } elseif (! Leads::stampValid($site, (string) $request->input('stamp', ''))) {
            $form = [...$form, 'state' => 'expired', 'old' => $input];
            $status = 422;
        } else {
            [$errors, $values] = $this->leads->check($input, $block['data'], $locale);
            $key = 'marketing-leads:'.$site->getKey().':'.$request->ip();
            if ($errors !== []) {
                $form = [...$form, 'state' => 'invalid', 'old' => $values, 'errors' => $errors];
                $status = 422;
            } elseif (RateLimiter::tooManyAttempts($key, (int) config('marketing.leads_per_hour'))) {
                $form = [...$form, 'state' => 'busy', 'old' => $values];
                $status = 429;
            } else {
                RateLimiter::hit($key, 3600);
                $this->leads->receive($site, $values, $slug, $locale);
            }
        }

        $origin = rtrim((string) config('marketing.url'), '/') ?: $request->getSchemeAndHttpHost();
        $html = view('marketing.page', [
            ...$this->public->view($site, $snapshot, $page, $locale, $origin, '', null, ['base' => '/assets', 'version' => (string) $site->cache_version]),
            'form' => $form,
        ])->render();

        return $this->guarded(response($html, $status), ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** The drafts, behind a signed link from the console (an hour). */
    public function preview(Request $request, string $site): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        if (! $request->hasValidSignatureWhileIgnoring(['page', 'lang'])) {
            return $this->unavailable();
        }
        $model = Site::query()->find($site);
        if ($model === null) {
            return $this->unavailable();
        }
        $snapshot = $this->websites->snapshot($model);
        $lang = (string) $request->query('lang', $snapshot['default_locale']);
        $locale = in_array($lang, $snapshot['locales'], true) ? $lang : $snapshot['default_locale'];
        $page = $this->public->page($snapshot, trim((string) $request->query('page', ''), '/'));
        $signature = ['expires' => (string) $request->query('expires'), 'signature' => (string) $request->query('signature')];
        $base = '/website-preview/'.$model->getKey();
        $html = view($page === null ? 'marketing.missing' : 'marketing.page', $this->public->view(
            $model, $snapshot, $page, $locale, $request->getSchemeAndHttpHost(), $base, $signature, ['base' => $base.'/assets', 'version' => 'p'.time()]
        ))->render();

        // The console shows it in a frame on the app's own host.
        return $this->guarded(response($html, $page === null ? 404 : 200), ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'], "'self'");
    }

    /** A preview's stylesheet, script and fonts (nothing secret in them: colours and our own code). */
    public function previewAsset(string $site, string $file): Response
    {
        $model = Site::query()->find($site);

        return ($model === null ? null : $this->asset($model, 'assets/'.$file, $this->websites->snapshot($model), null)) ?? response('', 404);
    }

    /**
     * The site's stylesheet (its colours over the templates), its script, its fonts and its brand's
     * pictures. Kept a year when the address names the version.
     */
    private function asset(Site $site, string $path, ?array $snapshot, ?string $version): ?Response
    {
        $forever = ['Cache-Control' => $version === null ? 'no-store' : 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff'];
        if ($path === 'assets/site.css') {
            return $snapshot === null ? response('', 404) : response($this->public->css($site, $snapshot), 200, ['Content-Type' => 'text/css; charset=UTF-8', ...$forever]);
        }
        if ($path === 'assets/site.js') {
            return response()->file(resource_path('marketing/site.js'), ['Content-Type' => 'text/javascript; charset=UTF-8', ...$forever]);
        }
        if (preg_match('#^assets/fonts/('.implode('|', self::FONTS).')\.woff2$#', $path, $match)) {
            return response()->file(resource_path("marketing/fonts/{$match[1]}.woff2"), [
                'Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        // The site's own pictures: only those the snapshot shows (the published one on the site's
        // host, the drafts in a preview). A picture never changes under its address.
        if (preg_match('#^assets/media/([0-9A-Za-z]{26})/(large|small)\.webp$#', $path, $match)) {
            $media = $snapshot === null || ! in_array($match[1], MediaLibrary::idsIn($snapshot), true)
                ? null : Media::query()->where('site_id', $site->getKey())->find($match[1]);
            $bytes = $media === null ? null : $this->media->bytes($media, $match[2]);
            if ($bytes === null) {
                return response('', 404);
            }

            return response($bytes, 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $version === null ? 'no-store' : 'public, max-age=31536000, immutable']);
        }
        // The partner's own uploaded logo and mark (its own only).
        if (preg_match('#^brand-assets/([0-9A-Za-z]{26})/([a-z_]+)$#', $path, $match)) {
            $column = PartnerBrand::ASSETS[$match[2]] ?? null;
            $file = $column === null || $match[1] !== $site->partner_id ? null : PartnerBrand::query()->where('partner_id', $site->partner_id)->value($column);
            $extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
            if ($file === null || ! isset(self::IMAGE_TYPES[$extension]) || ! Storage::disk('local')->exists($file)) {
                return response('', 404);
            }

            return response(Storage::disk('local')->get($file), 200, ['Content-Type' => self::IMAGE_TYPES[$extension], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
        }

        return null;
    }

    private function limited(Request $request): ?Response
    {
        if (RateLimiter::tooManyAttempts($key = 'marketing:'.$request->ip(), (int) config('marketing.per_minute'))) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * The page's own strict policy: the site's own stylesheet, script, pictures and fonts only; no
     * inline code (the structured data is not code); framed by nobody (a preview: by the app itself).
     */
    private function guarded(Response $response, array $headers = [], string $framedBy = "'none'"): Response
    {
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'self'; script-src 'self'; connect-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors {$framedBy}");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $framedBy === "'none'" ? 'DENY' : 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function unavailable(): Response
    {
        return $this->guarded(response()->view('marketing.unavailable', [], 404), ['Cache-Control' => 'no-store']);
    }
}
