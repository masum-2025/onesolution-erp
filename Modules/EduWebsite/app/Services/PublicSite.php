<?php

namespace Modules\EduWebsite\Services;

use App\Platform\Branding\BrandResolver;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
    /** Today's attendance numbers are kept this long (never names). */
    private const COUNTS_SECONDS = 600;

    public function __construct(
        private Place $place,
        private ModuleResolver $modules,
        private BrandResolver $brands,
    ) {}

    /** The page's stylesheet: the site's colours and the shared styles (no user text in it). */
    public function css(array $theme): string
    {
        $css = ":root{--p:{$theme['primary']};--a:{$theme['accent']}}".view('edu_website::site-css')->render();
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
        if (! $draft) {
            return $site->published['pages'] ?? [];
        }
        $pages = $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->orderBy('sort_order')->get();
        $blocks = $this->place->query(Block::class, $company)->whereIn('page_id', $pages->modelKeys())->orderBy('sort_order')->get()->groupBy('page_id');

        return $pages->map(fn (Page $page) => [
            'slug' => $page->slug, 'title' => $page->texts('title'), 'in_menu' => $page->in_menu, 'is_home' => $page->is_home,
            'blocks' => $blocks->get($page->getKey(), collect())->map(fn (Block $block) => ['type' => $block->type, 'data' => $block->data])->values()->all(),
        ])->values()->all();
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
                'attendance' => $this->attendance($company, (bool) ($block['data']['students'] ?? true), (bool) ($block['data']['staff'] ?? false)),
                default => ['media' => $media],
            },
        ])->all();
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

        return $rows->map(fn (Notice $notice) => [
            'id' => $notice->getKey(), 'title' => $notice->texts('title'), 'body' => $notice->texts('body'), 'pinned' => $notice->pinned,
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
