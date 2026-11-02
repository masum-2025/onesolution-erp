<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Applications;
use Modules\Recruitment\Services\Careers;
use Modules\Recruitment\Services\CvFiles;
use Modules\Recruitment\Services\Offers;
use Modules\Recruitment\Services\Openings;
use Modules\Recruitment\Services\Place;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's public careers page ("/careers/{slug}", for anyone; no
 * session or cookie): the jobs it lists, one job with the form to apply
 * (a CV, a signed token, a hidden field robots fill; a few an hour per
 * visitor), and the page the confirmation mail links to (a button to
 * confirm: a mail scanner opening the link confirms nothing). Unknown
 * addresses, or Recruitment off, show the same "not found".
 */
class PublicCareersController extends Controller
{
    /** Pages a visitor may open a minute. */
    private const PAGES_PER_MINUTE = 60;

    /** Applications a visitor may send an hour. */
    private const APPLIES_PER_HOUR = 10;

    /** HRM's employment types (rule hrm.employment_types) in schema.org's words; others are left out. */
    private const EMPLOYMENT_TYPES = [
        'permanent' => 'FULL_TIME', 'contract' => 'CONTRACTOR', 'part_time' => 'PART_TIME',
        'intern' => 'INTERN', 'daily_wage' => 'PER_DIEM', 'consultant' => 'CONTRACTOR',
    ];

    public function __construct(
        private Careers $careers,
        private Openings $openings,
        private Offers $offers,
        private Applications $applications,
        private CvFiles $files,
        private Place $place,
        private ModuleResolver $modules,
        private BrandResolver $brands,
        private Notifier $notifier,
        private LinkBuilder $links,
    ) {}

    public function index(Request $request, string $slug): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        if ($company === null) {
            return $this->missing($request);
        }
        $locale = $this->locale($request, $company);
        $slug = strtolower($slug);
        $brand = $this->brands->for($company->partner, $company);
        $seo = $this->seo($company, "/careers/{$slug}", $locale, $this->ui('intro', $locale), [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $company->displayName($locale),
            'url' => $this->links->to("/careers/{$slug}", $company->partner, $company),
            'logo' => $this->links->absolute($brand['logo_url'] ?? null, $company->partner),
        ]);

        return $this->page('recruitment::careers.index', $company, $slug, $locale, [
            'jobs' => $this->openings->public($company)->map(fn (Opening $opening) => $this->job($company, $opening, $locale, $slug))->all(),
            'seo' => $seo,
        ], ['Cache-Control' => 'public, max-age=60']);
    }

    /**
     * A job at its one address: an old or differently written one (the bare id, other words,
     * capitals) moves there for good (301). A job no longer open is gone (410), so search
     * engines drop it; one that never was here is the usual "not found".
     */
    public function show(Request $request, string $slug, string $opening): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        $found = $company === null ? null : $this->publicOpening($company, $opening);
        if ($found === null) {
            return $this->missing($request, $company, $slug, $company !== null && $this->wasHere($company, $opening));
        }
        $path = $this->careers->jobPath(strtolower($slug), $found);
        if ('/'.$request->path() !== $path) {
            $query = $request->getQueryString();

            return redirect()->to($path.($query ? '?'.$query : ''), 301);
        }
        $slug = strtolower($slug);
        $locale = $this->locale($request, $company);
        $applied = $request->query('applied') === '1';
        $job = $this->job($company, $found, $locale, $slug);

        return $this->page('recruitment::careers.job', $company, $slug, $locale, [
            'job' => $job, 'form' => $this->form($company, $applied),
            'seo' => $this->seo($company, $path, $locale, Str::limit(Str::squish($job['description']) ?: $job['title'], 155), $this->posting($company, $found, $job, $path, $locale)),
        ], ['Cache-Control' => $applied ? 'no-store' : 'public, max-age=60']);
    }

    /** The careers page and its open jobs for search engines, at their one addresses. */
    public function sitemap(Request $request, string $slug): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        if ($company === null) {
            return $this->missing($request);
        }
        $slug = strtolower($slug);
        $xml = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $entry = fn (string $path, ?string $date) => '<url><loc>'.$xml($this->links->to($path, $company->partner, $company)).'</loc>'
            .($date === null ? '' : "<lastmod>{$date}</lastmod>").'</url>';
        $urls = [$entry("/careers/{$slug}", null)];
        foreach ($this->openings->public($company) as $opening) {
            $urls[] = $entry($this->careers->jobPath($slug, $opening), $opening->updated_at?->toDateString());
        }

        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>'."\n", 200, [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An application from a job's form. Robots (the hidden field filled, or sent within seconds) are told it
     * went and nothing is kept; an old or foreign form, or wrong details, show the job again with what to fix.
     */
    public function apply(Request $request, string $slug, string $opening): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'recruitment-apply:'.$request->ip(), self::APPLIES_PER_HOUR)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        $company = $this->company($slug);
        $found = $company === null ? null : $this->publicOpening($company, $opening);
        if ($found === null) {
            return $this->missing($request, $company, $slug);
        }
        RateLimiter::hit($key, 3600);
        $locale = $this->locale($request, $company);
        $ui = fn (string $text) => __("recruitment::careers.{$text}", [], $locale);
        $old = array_map('strval', $request->only(['name', 'email', 'phone', 'cover_note']));
        $again = fn (array $errors, int $status) => $this->page('recruitment::careers.job', $company, $slug, $locale, [
            'job' => $this->job($company, $found, $locale, strtolower($slug)), 'form' => [...$this->form($company, false), 'errors' => $errors, 'old' => $old],
        ], ['Cache-Control' => 'no-store'])->setStatusCode($status);
        $sent = fn () => redirect()->to($this->careers->jobPath(strtolower($slug), $found).'?'.http_build_query(['lang' => $locale, 'applied' => '1']).'#apply', 303);

        $check = $this->careers->check($company, $request->input('t'));
        if (filled($request->input('website')) || $check === 'fast') {
            return $sent();
        }
        if ($check === 'bad') {
            return $again(['form' => [$ui('expired')]], 422);
        }
        $validator = Validator::make([...$old, 'cv' => $request->file('cv'), 'consent' => $request->input('consent')], [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{5,30}$/'],
            'cover_note' => ['nullable', 'string', 'max:3000'],
            'cv' => ['required', 'file'],
            'consent' => ['accepted'],
        ], [
            'required' => $ui('required'), 'email' => $ui('bad_email'), 'regex' => $ui('bad_phone'), 'max' => $ui('too_long'),
            'min' => $ui('too_short'), 'file' => $ui('required'), 'accepted' => $ui('consent_needed'),
        ]);
        if ($validator->fails()) {
            return $again($validator->errors()->toArray(), 422);
        }
        try {
            /** @var UploadedFile $cv */
            $cv = $request->file('cv');
            $this->applications->apply($company, $found, $validator->safe()->only(['name', 'email', 'phone', 'cover_note']), $cv, $locale, $slug);
        } catch (ValidationException $invalid) {
            return $again($invalid->errors(), 422);
        } catch (RecruitmentException $refused) {
            return $again(['form' => [__("recruitment::recruitment.errors.{$refused->errorCode()}", [], $locale)]], $refused->status());
        }

        return $sent();
    }

    /** The page the confirmation mail links to: a button that confirms (POST). */
    public function confirmPage(Request $request, string $slug, string $token): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        if ($company === null || ! $this->tokenShape($token)) {
            return $this->missing($request);
        }
        $locale = $this->locale($request, $company);

        return $this->page('recruitment::careers.confirm', $company, $slug, $locale, ['token' => $token, 'state' => 'ask'], ['Cache-Control' => 'no-store']);
    }

    public function confirm(Request $request, string $slug, string $token): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'recruitment-apply:'.$request->ip(), self::APPLIES_PER_HOUR)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 3600);
        $company = $this->company($slug);
        if ($company === null || ! $this->tokenShape($token)) {
            return $this->missing($request);
        }
        $locale = $this->locale($request, $company);
        $confirmed = $this->applications->confirm($company, $token);

        return $this->page('recruitment::careers.confirm', $company, $slug, $locale, ['token' => null, 'state' => $confirmed === null ? 'expired' : 'done'], ['Cache-Control' => 'no-store'])
            ->setStatusCode($confirmed === null ? 410 : 200);
    }

    /** A job offer through the candidate's link: the letter, and buttons to accept or decline while it stands. */
    public function offer(Request $request, string $slug, string $token): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        $offer = $company === null || ! $this->tokenShape($token) ? null : $this->offers->byToken($company, $token);
        if ($offer === null) {
            return $this->missing($request);
        }
        $locale = $this->locale($request, $company);

        return $this->page('recruitment::careers.offer', $company, $slug, $locale, $this->offerView($company, $offer, $token), ['Cache-Control' => 'no-store']);
    }

    /** The candidate's answer: accept or decline (a POST from the offer's page; a few an hour per visitor). */
    public function answer(Request $request, string $slug, string $token): Response
    {
        if (RateLimiter::tooManyAttempts($key = 'recruitment-apply:'.$request->ip(), self::APPLIES_PER_HOUR)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 3600);
        $company = $this->company($slug);
        $answer = (string) $request->input('answer');
        if ($company === null || ! $this->tokenShape($token) || ! in_array($answer, ['accept', 'decline'], true)) {
            return $this->missing($request);
        }
        $offer = $this->offers->answer($company, $token, $answer);
        if ($offer === null) {
            return $this->missing($request);
        }
        $locale = $this->locale($request, $company);

        return $this->page('recruitment::careers.offer', $company, $slug, $locale, $this->offerView($company, $offer, $token), ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerView(Organization $company, Offer $offer, string $token): array
    {
        $open = $offer->status === Offer::SENT && ! $this->offers->isPast($company, $offer);

        return [
            'offer' => ['status' => $open ? 'open' : ($offer->status === Offer::SENT ? Offer::EXPIRED : $offer->status), 'letter' => (string) $offer->letter],
            'token' => $token, 'robots' => 'noindex, nofollow',
        ];
    }

    private function company(string $slug): ?Organization
    {
        $company = $this->careers->company($slug);

        return $company !== null && $this->modules->isEnabled('hrm', $company) && $this->modules->isEnabled(Careers::MODULE, $company) ? $company : null;
    }

    /** The open, public job a job address part names ("accounts-officer-01j…" or the bare id). */
    private function publicOpening(Organization $company, string $part): ?Opening
    {
        $id = $this->careers->openingId($part);
        if ($id === null) {
            return null;
        }

        return $this->openings->public($company)->first(fn (Opening $opening) => strtolower($opening->getKey()) === $id);
    }

    /** A public job of this company that has closed, been filled or passed its closing day (gone, not unknown). Internal jobs stay unknown. */
    private function wasHere(Organization $company, string $part): bool
    {
        $id = $this->careers->openingId($part);

        return $id !== null && $this->place->query(Opening::class, $company)->whereKey($id)->where('is_public', true)
            ->where(fn ($query) => $query->whereIn('status', [Opening::CLOSED, Opening::FILLED])
                ->orWhere(fn ($open) => $open->where('status', Opening::OPEN)->where('closes_on', '<', $this->place->today($company)->toDateString())))
            ->exists();
    }

    /**
     * What search engines and shared links read: the page's one address in this language and
     * the others, a description, the logo, and structured data written with < > & escaped
     * (it cannot end its script element).
     *
     * @param  array<string, mixed>  $data  schema.org data.
     * @return array{canonical: string, alternates: array<string, string>, description: string, image: string|null, json_ld: HtmlString}
     */
    private function seo(Organization $company, string $path, string $locale, string $description, array $data): array
    {
        $url = fn (?string $code) => $this->links->to($path, $company->partner, $company).($code === null ? '' : '?lang='.$code);
        $logo = $this->links->absolute($this->brands->for($company->partner, $company)['logo_url'] ?? null, $company->partner);

        return [
            'canonical' => $url($locale),
            'alternates' => [...collect(LanguageRegistry::codes())->mapWithKeys(fn (string $code) => [$code => $url($code)])->all(), 'x-default' => $url(null)],
            'description' => $description,
            'image' => $logo,
            'json_ld' => new HtmlString(json_encode(array_filter($data, fn ($value) => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * The job as a schema.org JobPosting (what job search shows). Salaries are shown only
     * where the company chose to, as monthly amounts.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function posting(Organization $company, Opening $opening, array $job, string $path, string $locale): array
    {
        $digits = fn (int $minor) => intdiv($minor, 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
        $salary = $opening->show_salary && ($opening->salary_min_minor !== null || $opening->salary_max_minor !== null) ? [
            '@type' => 'MonetaryAmount',
            'currency' => $opening->currency,
            'value' => array_filter([
                '@type' => 'QuantitativeValue',
                'minValue' => $opening->salary_min_minor === null ? null : $digits($opening->salary_min_minor),
                'maxValue' => $opening->salary_max_minor === null ? null : $digits($opening->salary_max_minor),
                'unitText' => 'MONTH',
            ], fn ($value) => $value !== null),
        ] : null;
        $country = $company->country_code ?? Organization::query()->find($company->root_id)?->country_code ?? config('tenancy.defaults.country_code');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $job['title'],
            'description' => $job['description'] !== '' ? nl2br(e($job['description']), false) : $job['title'],
            'datePosted' => ($opening->opened_at ?? $opening->created_at)?->toDateString(),
            'validThrough' => $opening->closes_on === null ? null : $opening->closes_on->toDateString().'T23:59:59',
            'employmentType' => self::EMPLOYMENT_TYPES[strtolower((string) $opening->employment_type)] ?? null,
            'totalJobOpenings' => $opening->vacancies,
            'directApply' => true,
            'url' => $this->links->to($path, $company->partner, $company),
            'hiringOrganization' => array_filter([
                '@type' => 'Organization',
                'name' => $company->displayName($locale),
                'logo' => $this->links->absolute($this->brands->for($company->partner, $company)['logo_url'] ?? null, $company->partner),
            ]),
            'jobLocation' => ['@type' => 'Place', 'address' => array_filter([
                '@type' => 'PostalAddress',
                'addressLocality' => $job['unit'] !== '' ? $job['unit'] : null,
                'addressCountry' => $country,
            ])],
            'baseSalary' => $salary,
        ];
    }

    private function ui(string $key, string $locale): string
    {
        return __("recruitment::careers.{$key}", [], $locale);
    }

    private function tokenShape(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(Organization $company, Opening $opening, string $locale, string $slug): array
    {
        $amount = fn (?int $minor) => $minor === null ? null : number_format(intdiv($minor, 100)).' '.$opening->currency;

        return [
            'id' => $opening->getKey(), 'path' => $this->careers->jobPath($slug, $opening), 'title' => $opening->textIn('title', $locale), 'description' => $opening->textIn('description', $locale),
            'unit' => Organization::query()->find($opening->unit_id)?->displayName($locale) ?? '', 'vacancies' => $opening->vacancies,
            'employment_type' => $opening->employment_type, 'closes_on' => $opening->closes_on?->toDateString(),
            'salary' => $opening->show_salary && ($opening->salary_min_minor !== null || $opening->salary_max_minor !== null)
                ? implode(' – ', array_filter([$amount($opening->salary_min_minor), $amount($opening->salary_max_minor)])) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function form(Organization $company, bool $applied): array
    {
        return ['token' => $this->careers->token($company), 'applied' => $applied, 'max_kb' => $this->files->maxKb($company), 'errors' => [], 'old' => []];
    }

    private function locale(Request $request, Organization $company): string
    {
        $asked = (string) $request->input('lang', '');

        return in_array($asked, LanguageRegistry::codes(), true) ? $asked : $this->notifier->locale($company);
    }

    private function limited(Request $request): ?Response
    {
        if (RateLimiter::tooManyAttempts($key = 'recruitment-careers:'.$request->ip(), self::PAGES_PER_MINUTE)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function page(string $view, Organization $company, string $slug, string $locale, array $data, array $headers = []): Response
    {
        // Only pages made for search engines (with their SEO data) may be indexed: not confirmations, offers or a form shown again.
        if (! isset($data['seo'])) {
            $headers['X-Robots-Tag'] ??= 'noindex, nofollow';
        }
        $brand = $this->brands->for($company->partner, $company);
        $html = view($view, [
            ...$data, 'company' => $company->displayName($locale), 'brand' => $brand, 'slug' => $slug, 'locale' => $locale,
            'languages' => LanguageRegistry::codes(), 'ui' => fn (string $key, array $replace = []) => __("recruitment::careers.{$key}", $replace, $locale),
        ])->render();

        return $this->guarded(response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), $headers);
    }

    /** "Not found" (404), or for a job that was here and closed, "gone" (410); never indexed. */
    private function missing(Request $request, ?Organization $company = null, ?string $slug = null, bool $gone = false): Response
    {
        $locale = $company === null ? app()->getLocale() : $this->locale($request, $company);
        $html = view('recruitment::careers.missing', [
            'ui' => fn (string $key, array $replace = []) => __("recruitment::careers.{$key}", $replace, $locale), 'locale' => $locale,
            'back' => $slug === null ? null : "/careers/{$slug}",
        ])->render();

        return $this->guarded(response($html, $gone ? 410 : 404, ['Content-Type' => 'text/html; charset=UTF-8']), ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * A strict policy: the inline stylesheet allowed by hash, no scripts at all, pictures from the app only,
     * forms only to the app, no framing.
     *
     * @param  array<string, string>  $headers
     */
    private function guarded(Response $response, array $headers = []): Response
    {
        $html = (string) $response->getContent();
        $styles = preg_match_all('#<style>(.*?)</style>#s', $html, $found)
            ? implode(' ', array_map(fn (string $code) => "'sha256-".base64_encode(hash('sha256', $code, true))."'", array_unique($found[1])))
            : "'none'";
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src {$styles}; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
