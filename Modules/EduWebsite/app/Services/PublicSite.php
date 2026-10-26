<?php

namespace Modules\EduWebsite\Services;

use App\Platform\Branding\BrandResolver;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Modules\Attendance\Services\TodayCounts as StaffCounts;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Services\TodayCounts as StudentCounts;

/**
 * What a public page shows, ready for the template: the site's names and
 * colours, the menu, and the page's blocks with what they need filled in
 * (published notices, programs from Education, pictures and files, today's
 * attendance as numbers only). The public sees the published snapshot;
 * a preview sees the draft. Texts are picked in the visitor's language
 * (else the site's default, else English).
 */
class PublicSite
{
    /** The fonts the sites use (served by the site itself; files in resources/fonts). */
    public const FONTS = ['hind-bengali-400', 'hind-bengali-600', 'inter-latin'];

    /** A notice this many days old or newer is marked "new". */
    private const NEW_NOTICE_DAYS = 7;

    /** Today's attendance numbers are kept this long (never names). */
    private const COUNTS_SECONDS = 600;

    public function __construct(
        private Place $place,
        private ModuleResolver $modules,
        private BrandResolver $brands,
    ) {}

    /**
     * The page's stylesheet: the site's fonts (Bangla letters load the Bangla font only when a page has
     * them), its colours and the shared styles (no user text in it). $base is the site's own path.
     */
    public function css(array $theme, string $base = ''): string
    {
        $bengali = 'U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0-1CF9,U+200C-200D,U+20B9,U+25CC,U+A8F1';
        $latin = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
        $fonts = "@font-face{font-family:Hind Siliguri;font-weight:400;font-display:swap;src:url({$base}/fonts/hind-bengali-400.woff2);unicode-range:{$bengali}}"
            ."@font-face{font-family:Hind Siliguri;font-weight:500 800;font-display:swap;src:url({$base}/fonts/hind-bengali-600.woff2);unicode-range:{$bengali}}"
            ."@font-face{font-family:Inter;font-weight:100 900;font-display:swap;src:url({$base}/fonts/inter-latin.woff2);unicode-range:{$latin}}";
        $css = $fonts.":root{--p:{$theme['primary']};--a:{$theme['accent']}}".view('edu_website::site-css')->render();
        // It is printed escaped like any text: nothing in it may be changed by escaping.
        if (preg_match('/[<>&"\x27]/', $css)) {
            throw new \LogicException('The website stylesheet must not contain < > & or quotes.');
        }

        return $css;
    }

    /** The locale to show: asked for and offered, else the site's default. */
    public function locale(Site $site, ?string $asked): string
    {
        return in_array($asked, $site->locales ?? [], true) ? $asked : $site->default_locale;
    }

    /**
     * @return list<array{slug: string, title: array<string, string>, in_menu: bool, is_home: bool, blocks: list<array{type: string, data: array<string, mixed>}>}>
     */
    public function pages(Organization $company, Site $site, bool $draft): array
    {
        return $draft ? app(Sites::class)->snapshot($company, $site) : ($site->published['pages'] ?? []);
    }

    /**
     * Everything the layout needs around a page.
     *
     * @return array<string, mixed>
     */
    public function frame(Organization $company, Site $site, array $pages, string $locale, string $base): array
    {
        $text = fn (?array $texts) => $this->text($texts, $locale, $site->default_locale);
        $brand = $this->brands->for($company->partner);
        $logo = $site->logo_media_id === null ? null : $this->place->query(Media::class, $company)->whereKey($site->logo_media_id)->first();

        return [
            'site' => $site,
            'locale' => $locale,
            'base' => $base,
            'title' => $text($site->texts('title')),
            'tagline' => $text($site->texts('tagline')),
            'theme' => [
                'primary' => $this->color($site->theme['primary'] ?? null) ?? '#1d4ed8',
                'accent' => $this->color($site->theme['accent'] ?? null) ?? '#f59e0b',
            ],
            'logo' => $logo === null ? null : "{$base}/media/{$logo->getKey()}/small",
            'menu' => collect($pages)->filter(fn ($page) => $page['in_menu'])->map(fn ($page) => [
                'label' => $text($page['title']), 'url' => $page['is_home'] ? ($base === '' ? '/' : $base) : "{$base}/{$page['slug']}",
            ])->values()->all(),
            'contact' => [
                'address' => $text($site->contact['address'] ?? null),
                'hours' => $text($site->contact['hours'] ?? null),
                'phone' => $site->contact['phone'] ?? null,
                'email' => $site->contact['email'] ?? null,
                'map_url' => $site->contact['map_url'] ?? null,
            ],
            'powered_by' => $brand['powered_by'] ?? null,
            'text' => $text,
            'date' => fn (string $date, string $format = 'LL') => $this->date($date, $format, $locale),
            // The company's today (Y-m-d), for what is past.
            'today' => $this->place->today($company)->toDateString(),
            // A whole number in the visitor's digits and grouping (১,২০০ in Bangla).
            'number' => fn (int $value) => (string) (new \NumberFormatter($locale, \NumberFormatter::DECIMAL))->format($value),
        ];
    }

    /**
     * A page's blocks with their data filled in for showing.
     *
     * @param  array{blocks: list<array{type: string, data: array<string, mixed>}>}  $page
     * @return list<array{type: string, data: array<string, mixed>, extra: array<string, mixed>}>
     */
    public function blocks(Organization $company, Site $site, array $page, string $base): array
    {
        $media = $this->mediaFor($company, collect($page['blocks'])->flatMap(fn ($block) => BlockTypes::mediaIds($block['type'], $block['data']))->unique()->values()->all(), $base);

        return collect($page['blocks'])->map(fn ($block) => [
            'type' => $block['type'],
            'data' => $block['data'],
            'extra' => match ($block['type']) {
                'notices' => ['notices' => $this->notices($company, $site, (int) ($block['data']['count'] ?? 5), $base)],
                'programs' => ['programs' => ($block['data']['source'] ?? 'manual') === 'education' ? $this->programs($company) : []],
                'enquiry' => ['levels' => ($block['data']['ask_level'] ?? true) ? app(Enquiries::class)->levels($company) : [], 'token' => app(Enquiries::class)->token($site)],
                'attendance' => $this->attendance($company, (bool) ($block['data']['students'] ?? true), (bool) ($block['data']['staff'] ?? false)),
                default => ['media' => $media],
            },
        ])->all();
    }

    /**
     * What search engines and shared links read about a page: its one address (the site's own domain when
     * proven) and the same page in each language, a description, a picture, and the school as structured
     * data. The JSON is written with < > & as escapes, so it cannot end its script element.
     *
     * @param  array<string, mixed>|null  $page  The page (null for notices).
     * @return array{canonical: string, alternates: array<string, string>, description: string, image: string|null, json_ld: HtmlString}
     */
    public function seo(Site $site, string $origin, string $path, string $locale, ?array $page, ?string $description = null): array
    {
        $text = fn (?array $texts) => $this->text($texts, $locale, $site->default_locale);
        $url = fn (string $code) => $origin.($path === '' ? '/' : '/'.$path).($code === $site->default_locale ? '' : '?lang='.$code);
        $picture = fn (?string $id) => $id === null ? null : "{$origin}/media/{$id}/large";
        $image = $picture(($page['share_media_id'] ?? null) ?: ($site->share_media_id ?: $site->logo_media_id));
        $school = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            'name' => $text($site->texts('title')),
            'description' => $text($site->texts('tagline')) ?: null,
            'url' => $origin.'/',
            'logo' => $picture($site->logo_media_id),
            'telephone' => $site->contact['phone'] ?? null,
            'email' => $site->contact['email'] ?? null,
            'address' => ($street = $text($site->contact['address'] ?? null)) === '' ? null : ['@type' => 'PostalAddress', 'streetAddress' => $street],
            'hasMap' => $site->contact['map_url'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            'canonical' => $url($locale),
            'alternates' => [...collect($site->locales ?? [])->mapWithKeys(fn (string $code) => [$code => $url($code)])->all(), 'x-default' => $url($site->default_locale)],
            'description' => (string) ($description ?: ($text($page['description'] ?? null) ?: $text($site->texts('tagline')))),
            'image' => $image,
            'json_ld' => new HtmlString(json_encode($school, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)),
        ];
    }

    /** The published pages and notices for search engines, at the site's one address. */
    public function sitemap(Organization $company, Site $site, string $origin): string
    {
        $xml = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $entry = fn (string $path, ?string $date) => '<url><loc>'.$xml($origin.$path).'</loc>'.($date === null ? '' : '<lastmod>'.$date.'</lastmod>').'</url>';
        $published = $site->published_at?->toDateString();
        $urls = collect($site->published['pages'] ?? [])->map(fn ($page) => $entry($page['is_home'] ? '/' : '/'.$page['slug'], $published))->all();
        $urls[] = $entry('/notices', null);
        foreach ($this->notices($company, $site, 100, '') as $notice) {
            $urls[] = $entry($notice['url'], $notice['date']);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."
".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>'."
";
    }

    /**
     * Published notices, pinned first, then newest.
     *
     * @return list<array<string, mixed>>
     */
    public function notices(Organization $company, Site $site, int $limit, string $base): array
    {
        $rows = $this->place->query(Notice::class, $company)->where('site_id', $site->getKey())->where('is_published', true)
            ->whereDate('published_on', '<=', $this->place->today($company)->toDateString())
            ->orderByDesc('pinned')->orderByDesc('published_on')->orderByDesc('created_at')->limit(max(1, min(100, $limit)))->get();

        $fresh = $this->place->today($company)->subDays(self::NEW_NOTICE_DAYS)->toDateString();

        return $rows->map(fn (Notice $notice) => [
            'id' => $notice->getKey(), 'fresh' => $notice->published_on->toDateString() >= $fresh, 'title' => $notice->texts('title'), 'body' => $notice->texts('body'), 'pinned' => $notice->pinned,
            'date' => $notice->published_on->toDateString(), 'url' => "{$base}/notices/{$notice->getKey()}",
            'file' => $notice->media_id === null ? null : "{$base}/media/{$notice->media_id}/file",
        ])->all();
    }

    public function notice(Organization $company, Site $site, string $id, string $base): ?array
    {
        return collect($this->notices($company, $site, 100, $base))->firstWhere('id', $id);
    }

    /** A date in the visitor's language, with that language's digits (১৭ অক্টো in Bangla). */
    public function date(string $date, string $format, string $locale): string
    {
        $text = CarbonImmutable::parse($date)->locale($locale)->isoFormat($format);
        $digits = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);

        return strtr($text, array_combine(array_map('strval', range(0, 9)), array_map(fn (int $digit) => (string) $digits->format($digit), range(0, 9))));
    }

    public function text(?array $texts, string $locale, string $fallback): string
    {
        $texts ??= [];

        return (string) ($texts[$locale] ?? null ?: ($texts[$fallback] ?? null ?: ($texts['en'] ?? null ?: (collect($texts)->first(fn ($value) => is_string($value) && $value !== '') ?? ''))));
    }

    /**
     * Programs and their classes from Education (names only).
     *
     * @return list<array{name: array<string, string>, levels: list<array<string, string>>}>
     */
    private function programs(Organization $company): array
    {
        if (! class_exists(AcademicDirectory::class) || ! $this->modules->isEnabled('education', $company)) {
            return [];
        }

        return collect(app(AcademicDirectory::class)->levels($company))->groupBy('program_id')
            ->map(fn ($levels) => ['name' => $levels->first()['program']['name'], 'levels' => $levels->pluck('name')->all()])->values()->all();
    }

    /**
     * Today's attendance as numbers (students and/or staff), where those modules are on.
     *
     * @return array{students: array<string, mixed>|null, staff: array<string, mixed>|null}
     */
    private function attendance(Organization $company, bool $students, bool $staff): array
    {
        $count = fn (string $module, string $service) => class_exists($service) && $this->modules->isEnabled($module, $company)
            ? Cache::remember("edu_website:counts:{$module}:{$company->getKey()}", self::COUNTS_SECONDS, fn () => app($service)->forCompany($company))
            : null;

        return [
            'students' => $students ? $count('student_attendance', StudentCounts::class) : null,
            'staff' => $staff ? $count('attendance', StaffCounts::class) : null,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, kind: string, name: string, small: string, large: string, file: string, width: int|null, height: int|null}>
     */
    private function mediaFor(Organization $company, array $ids, string $base): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->place->query(Media::class, $company)->whereKey($ids)->get()->mapWithKeys(fn (Media $media) => [$media->getKey() => [
            'id' => $media->getKey(), 'kind' => $media->kind, 'name' => $media->name, 'width' => $media->width, 'height' => $media->height,
            'small' => "{$base}/media/{$media->getKey()}/small", 'large' => "{$base}/media/{$media->getKey()}/large", 'file' => "{$base}/media/{$media->getKey()}/file",
        ]])->all();
    }

    private function color(?string $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }
}
