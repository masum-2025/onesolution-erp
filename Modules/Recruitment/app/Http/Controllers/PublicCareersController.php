<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
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

        return $this->page('recruitment::careers.index', $company, $slug, $locale, [
            'jobs' => $this->openings->public($company)->map(fn (Opening $opening) => $this->job($company, $opening, $locale))->all(),
        ], ['Cache-Control' => 'public, max-age=60']);
    }

    public function show(Request $request, string $slug, string $opening): Response
    {
        if ($limited = $this->limited($request)) {
            return $limited;
        }
        $company = $this->company($slug);
        $found = $company === null ? null : $this->publicOpening($company, $opening);
        if ($found === null) {
            return $this->missing($request, $company, $slug);
        }
        $locale = $this->locale($request, $company);
        $applied = $request->query('applied') === '1';

        return $this->page('recruitment::careers.job', $company, $slug, $locale, [
            'job' => $this->job($company, $found, $locale), 'form' => $this->form($company, $applied),
        ], ['Cache-Control' => $applied ? 'no-store' : 'public, max-age=60']);
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
            'job' => $this->job($company, $found, $locale), 'form' => [...$this->form($company, false), 'errors' => $errors, 'old' => $old],
        ], ['Cache-Control' => 'no-store'])->setStatusCode($status);
        $sent = fn () => redirect()->to("/careers/{$slug}/jobs/{$found->getKey()}?".http_build_query(['lang' => $locale, 'applied' => '1']).'#apply', 303);

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

    private function publicOpening(Organization $company, string $id): ?Opening
    {
        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $id)) {
            return null;
        }

        return $this->openings->public($company)->first(fn (Opening $opening) => $opening->getKey() === strtolower($id) || $opening->getKey() === $id);
    }

    private function tokenShape(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(Organization $company, Opening $opening, string $locale): array
    {
        $amount = fn (?int $minor) => $minor === null ? null : number_format(intdiv($minor, 100)).' '.$opening->currency;

        return [
            'id' => $opening->getKey(), 'title' => $opening->textIn('title', $locale), 'description' => $opening->textIn('description', $locale),
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
        $brand = $this->brands->for($company->partner, $company);
        $html = view($view, [
            ...$data, 'company' => $company->displayName($locale), 'brand' => $brand, 'slug' => $slug, 'locale' => $locale,
            'languages' => LanguageRegistry::codes(), 'ui' => fn (string $key, array $replace = []) => __("recruitment::careers.{$key}", $replace, $locale),
        ])->render();

        return $this->guarded(response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), $headers);
    }

    private function missing(Request $request, ?Organization $company = null, ?string $slug = null): Response
    {
        $locale = $company === null ? app()->getLocale() : $this->locale($request, $company);
        $html = view('recruitment::careers.missing', [
            'ui' => fn (string $key, array $replace = []) => __("recruitment::careers.{$key}", $replace, $locale), 'locale' => $locale,
            'back' => $slug === null ? null : "/careers/{$slug}",
        ])->render();

        return $this->guarded(response($html, 404, ['Content-Type' => 'text/html; charset=UTF-8']), ['Cache-Control' => 'no-store']);
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
