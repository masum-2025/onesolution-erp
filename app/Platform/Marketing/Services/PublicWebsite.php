<?php

namespace App\Platform\Marketing\Services;

use App\Platform\Branding\BrandResolver;
use App\Platform\Marketing\Models\Media;
use App\Platform\Marketing\Models\Site;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Partners\Enums\DomainServes;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Support\LocaleResolver;
use App\Platform\Support\MoneyText;
use Carbon\CarbonImmutable;
use IntlDateFormatter;
use NumberFormatter;

/**
 * What a company website page shows, from a snapshot (published, or the
 * drafts for a preview): the language (the main one at "/", others under
 * "/{code}/"), texts in it (the main language when one is missing), links
 * (site paths, "app:" pages at the partner's app address), the brand's
 * colours unless the site sets its own, and the parts read live: module
 * and plan names, prices from the plan catalogue, counts. Blocks outside
 * their days (or an offer past its end) are left out.
 */
class PublicWebsite
{
    public function __construct(
        private BrandResolver $brands,
        private ModuleRegistry $modules,
        private PlanCatalog $plans,
        private SectorCatalog $sectors,
    ) {}

    /** The ready-made colour pairs (main, second) a look link may try (the console offers the same). */
    public const PALETTES = [
        ['#2B4C9B', '#23A562'], ['#6D28D9', '#DB2777'], ['#047857', '#65A30D'], ['#BE123C', '#D97706'], ['#0369A1', '#0D9488'],
    ];

    /**
     * A look link's choices from the address, each checked against what exists; anything else is ignored.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string|int>
     */
    public function lookChoices(array $query): array
    {
        $pick = fn (string $key, array $allowed) => is_string($query[$key] ?? null) && in_array($query[$key], $allowed, true) ? $query[$key] : null;
        $palette = $query['palette'] ?? null;

        return array_filter([
            'template' => $pick('template', Site::TEMPLATES),
            'effect' => $pick('effect', BlockTypes::EFFECTS),
            'product' => $pick('product', BlockTypes::PRODUCT_STYLES),
            'palette' => is_string($palette) && ctype_digit($palette) && isset(self::PALETTES[(int) $palette]) ? (int) $palette : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The snapshot and page as they look with a look link's choices (the first slider and product
     * section of the page). Only shown, never saved.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function withLook(array $snapshot, array $page, array $choices): array
    {
        if (isset($choices['template'])) {
            $snapshot['template'] = $choices['template'];
        }
        foreach (['effect' => ['hero_slider', 'effect'], 'product' => ['product', 'style']] as $key => [$type, $field]) {
            foreach (isset($choices[$key]) ? $page['blocks'] : [] as $index => $block) {
                if ($block['type'] === $type) {
                    $page['blocks'][$index]['data'][$field] = $choices[$key];
                    break;
                }
            }
        }

        return [$snapshot, $page];
    }

    /** A look link's colours over the site's stylesheet. */
    public function lookCss(mixed $palette): string
    {
        $pair = is_string($palette) && ctype_digit($palette) ? (self::PALETTES[(int) $palette] ?? null) : null;

        return $pair === null ? '' : ":root{--blue:{$pair[0]};--green:{$pair[1]}}
";
    }

    /**
     * The language a path asks for and the page path without it.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: string}
     */
    public function split(array $snapshot, string $path): array
    {
        $path = trim($path, '/');
        $first = explode('/', $path, 2)[0];
        if ($first !== $snapshot['default_locale'] && in_array($first, $snapshot['locales'], true)) {
            return [$first, trim(substr($path, strlen($first)), '/')];
        }

        return [$snapshot['default_locale'], $path];
    }

    /** @param  array<string, mixed>  $snapshot */
    public function page(array $snapshot, string $slug): ?array
    {
        return collect($snapshot['pages'])->first(fn ($page) => $slug === '' ? $page['is_home'] : $page['slug'] === $slug);
    }

    /**
     * Everything the layout and the blocks need.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $page  Null: the "not found" page.
     * @param  string  $base  Where the site's paths start ("" on its own host, the preview's address otherwise).
     * @param  array<string, string>|null  $signature  A preview's signature: its links name the page in the query instead.
     * @param  array{base: string, version: string}  $assets  Where the stylesheet, script and fonts are, and their version.
     * @param  array{token: string, choices: array<string, string|int>}|null  $look  A look link: its panel, and its links keep it.
     * @return array<string, mixed>
     */
    public function view(Site $site, array $snapshot, ?array $page, string $locale, string $origin, string $base, ?array $signature, array $assets, ?array $look = null): array
    {
        $main = $snapshot['default_locale'];
        $settings = $snapshot['settings'] ?? [];
        $brand = $this->brands->for($site->partner);
        $tx = fn ($value): string => is_array($value) ? (string) (($value[$locale] ?? '') !== '' ? $value[$locale] : ($value[$main] ?? '')) : (string) ($value ?? '');
        $appBase = $this->appBase($site);
        // A page of the site in a language: "/bn/pricing" on the site, "?page=pricing&lang=bn&…" in a preview.
        $lookQuery = $look === null ? '' : '?'.http_build_query(['look' => $look['token'], ...$look['choices']]);
        $address = fn (string $path, string $code) => $signature === null
            ? $base.($code === $main ? '' : '/'.$code).'/'.$path.$lookQuery
            : $base.'?'.http_build_query([...$signature, 'page' => $path, 'lang' => $code]);
        $link = function (?string $url) use ($address, $locale, $appBase): string {
            $url = (string) $url;
            if ($url === '') {
                return '#';
            }
            if (str_starts_with($url, 'app:')) {
                return $appBase.substr($url, 4);
            }
            if (str_starts_with($url, '/')) {
                [$path, $anchor] = array_pad(explode('#', $url, 2), 2, null);

                return $address(trim($path, '/'), $locale).($anchor !== null ? '#'.$anchor : '');
            }

            return $url;
        };
        $preview = $signature !== null;
        $today = CarbonImmutable::now()->toDateString();
        $blocks = collect($page['blocks'] ?? [])
            ->filter(fn ($block) => ($block['visible_from'] ?? null) === null || $block['visible_from'] <= $today)
            ->filter(fn ($block) => ($block['visible_until'] ?? null) === null || $block['visible_until'] >= $today)
            ->map(fn ($block) => $this->enrich($site, $block, $locale, $snapshot))
            ->filter()
            ->values()->all();

        $notice = $settings['notice'] ?? [];
        $noticeOn = ($notice['enabled'] ?? false)
            && (($notice['from'] ?? null) === null || $notice['from'] <= $today)
            && (($notice['until'] ?? null) === null || $notice['until'] >= $today);

        $slug = $page['slug'] ?? '';

        // The site's own pictures this page shows: where they are, their size (no jumping while they
        // load) and the words read out instead. A picture no longer kept is left out.
        $rows = Media::query()->where('site_id', $site->getKey())
            ->whereIn('id', MediaLibrary::idsIn(['blocks' => $blocks, 'seo' => $settings['seo'] ?? []]))
            ->get(['id', 'width', 'height', 'alt'])->keyBy('id');
        $picture = function (?string $id) use ($rows, $assets, $tx): ?array {
            $row = $id === null ? null : $rows->get($id);

            return $row === null ? null : [
                'src' => "{$assets['base']}/media/{$row->id}/large.webp",
                'small' => "{$assets['base']}/media/{$row->id}/small.webp",
                'srcset' => "{$assets['base']}/media/{$row->id}/small.webp ".MediaLibrary::SIZES['small']."w, {$assets['base']}/media/{$row->id}/large.webp {$row->width}w",
                'width' => $row->width, 'height' => $row->height, 'alt' => $tx($row->alt),
            ];
        };

        return [
            'site' => $site,
            'template' => $snapshot['template'],
            'locale' => $locale,
            'dir' => LocaleResolver::direction($locale),
            'tx' => $tx,
            'link' => $link,
            'number' => fn (int $value) => (new NumberFormatter($locale, NumberFormatter::DECIMAL))->format($value),
            'date' => fn (string $day) => (new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC'))->format(CarbonImmutable::parse($day, 'UTC')),
            'ui' => fn (string $key, array $replace = []) => __("marketing.public.{$key}", $replace, $locale),
            'brand' => [
                'name' => (string) ($brand['name'] ?? $site->partner->name),
                'mark' => $brand['mark_url'] ?? null,
                'logo' => $brand['logo_url'] ?? null,
            ],
            'home' => $link('/'),
            'languages' => collect($snapshot['locales'])->map(fn ($code) => [
                'code' => $code,
                'label' => __("marketing.public.lang_{$code}", [], $code),
                'url' => $address($slug, $code),
                'current' => $code === $locale,
            ])->all(),
            'header' => $settings['header'] ?? [],
            'notice' => $noticeOn ? $notice : null,
            'contact' => $settings['contact'] ?? [],
            'social' => array_filter($settings['social'] ?? []),
            'footer' => $settings['footer'] ?? [],
            'page' => $page,
            'blocks' => $blocks,
            'assets' => $assets,
            // The contact form posts to the site itself (never in a preview); a post's answer fills in "form".
            'form_action' => $preview ? null : $base.'/contact',
            'form_stamp' => Leads::stamp($site),
            'form' => null,
            'picture' => $picture,
            'preview' => $preview,
            'look' => $look === null ? null : [
                'token' => $look['token'],
                // The form comes back to this page (without the old choices: the form sends them all).
                'action' => $base.($locale === $main ? '' : '/'.$locale).'/'.$slug,
                'template' => $snapshot['template'],
                'effect' => collect($page['blocks'] ?? [])->firstWhere('type', 'hero_slider')['data']['effect'] ?? null,
                'product' => collect($page['blocks'] ?? [])->firstWhere('type', 'product')['data']['style'] ?? null,
                'palette' => $look['choices']['palette'] ?? null,
                'templates' => Site::TEMPLATES, 'effects' => BlockTypes::EFFECTS, 'products' => BlockTypes::PRODUCT_STYLES,
                'palettes' => array_keys(self::PALETTES),
            ],
            'seo' => $page === null ? null : $this->seo($snapshot, $page, $locale, $origin, $tx, $blocks, (string) ($brand['name'] ?? $site->partner->name), $brand['logo_url'] ?? null, $picture($settings['seo']['image'] ?? null)['src'] ?? null),
            'verification' => $preview ? [] : array_filter([
                'google-site-verification' => $settings['seo']['google_verification'] ?? null,
                'msvalidate.01' => $settings['seo']['bing_verification'] ?? null,
            ]),
            'year' => CarbonImmutable::now()->year,
        ];
    }

    /** The site's stylesheet: the brand's colours (or the site's own) over the templates. */
    public function css(Site $site, array $snapshot): string
    {
        $brand = $this->brands->for($site->partner);
        $colors = $snapshot['settings']['colors'] ?? [];
        $bands = $brand['band_colors'] ?? [];
        $hex = fn (?string $value, string $fallback) => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : $fallback;
        $primary = $hex($colors['primary'] ?? null, $hex($brand['primary_color'] ?? null, '#2B4C9B'));
        $accent = $hex($colors['accent'] ?? null, $hex($bands[2] ?? null, '#23A562'));
        $highlight = $hex($colors['highlight'] ?? null, $hex($bands[1] ?? null, '#DD5144'));

        return ":root{--blue:{$primary};--green:{$accent};--red:{$highlight}}\n".file_get_contents(resource_path('marketing/site.css'));
    }

    /** The sitemap: every page in every language, each naming the others. */
    public function sitemap(array $snapshot, string $origin): string
    {
        $main = $snapshot['default_locale'];
        $url = fn (string $code, string $slug) => rtrim($origin, '/').($code === $main ? '' : '/'.$code).'/'.$slug;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        foreach ($snapshot['pages'] as $page) {
            if ($page['seo']['noindex'] ?? false) {
                continue;
            }
            foreach ($snapshot['locales'] as $code) {
                $xml .= '<url><loc>'.e($url($code, $page['slug'])).'</loc>';
                foreach ($snapshot['locales'] as $other) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="'.e($other).'" href="'.e($url($other, $page['slug'])).'"/>';
                }
                $xml .= '</url>';
            }
        }

        return $xml.'</urlset>';
    }

    /** Where "app:" links go: the partner's own app address once proven, else the platform's. */
    private function appBase(Site $site): string
    {
        if (! $site->partner->is_house) {
            $domain = PartnerDomain::query()->where('partner_id', $site->partner_id)->whereNull('organization_id')
                ->where('status', DomainStatus::Active)->where('serves', DomainServes::App)->orderBy('created_at')->value('host');
            if ($domain !== null) {
                return Websites::origin($domain);
            }
        }

        return rtrim((string) config('app.url'), '/');
    }

    /**
     * A block with what is read live added (null: it shows nothing today).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function enrich(Site $site, array $block, string $locale, array $snapshot): ?array
    {
        $data = $block['data'];
        $label = fn (string $key) => $this->modules->has($key) ? $this->modules->get($key)->label($locale) : $key;

        switch ($block['type']) {
            case 'offer':
                if (($data['ends_at'] ?? null) !== null) {
                    $end = CarbonImmutable::parse($data['ends_at'], 'UTC');
                    if ($end->isPast()) {
                        return null;
                    }
                    $left = (int) now()->diffInMinutes($end, true);
                    $block['countdown'] = ['days' => intdiv($left, 1440), 'hours' => intdiv($left % 1440, 60), 'minutes' => $left % 60, 'ends' => $end->toIso8601String()];
                }
                break;
            case 'stats':
                $block['data']['items'] = array_map(fn ($item) => [...$item, 'value' => match ($item['auto'] ?? null) {
                    'modules' => count($this->modules->keys()),
                    'sectors' => count($this->sectors->keys()),
                    'languages' => count($snapshot['locales']),
                    default => $item['value'] ?? null,
                }], $data['items']);
                break;
            case 'ticker':
                $block['labels'] = $data['source'] === 'modules'
                    ? array_map($label, $this->modules->keys())
                    : array_map(fn ($item) => (string) (($item[$locale] ?? '') ?: ($item[$snapshot['default_locale']] ?? '')), $data['items'] ?? []);
                break;
            case 'product':
                $block['data']['groups'] = array_map(fn ($group) => [...$group, 'labels' => array_map($label, array_values(array_filter($group['modules'], fn ($key) => $this->modules->has($key))))], $data['groups']);
                $block['module_count'] = count($this->modules->keys());
                break;
            case 'pricing':
                $block['plans'] = $this->plans($site, $data, $locale);
                if ($block['plans'] === []) {
                    return null;
                }
                break;
        }

        return $block;
    }

    /**
     * The plans a pricing block names, with their prices in its currency: the
     * platform's catalogue on its own site; a partner's own active plans on
     * its site (an archived one, or one without that currency, just drops out).
     *
     * @return list<array<string, mixed>>
     */
    private function plans(Site $site, array $data, string $locale): array
    {
        $own = $site->partner->is_house ? null : PartnerPlan::query()->with('prices')->where('partner_id', $site->partner_id)
            ->where('status', PartnerPlan::ACTIVE)->whereIn('id', $data['plans'])->get()->keyBy('id');
        // One shape for both: name, description and prices [{currency, period, amount_minor}].
        $find = function (string $key) use ($own, $locale): ?array {
            if ($own !== null) {
                $plan = $own->get($key);

                return $plan === null ? null : [
                    'name' => $plan->label($locale), 'description' => $plan->textIn('description', $locale) ?: null,
                    'prices' => $plan->prices->map(fn ($row) => ['currency' => $row->currency_code, 'period' => $row->period, 'amount_minor' => $row->amount_minor])->all(),
                ];
            }
            if (! $this->plans->has($key)) {
                return null;
            }
            $plan = $this->plans->get($key);

            return ['name' => $plan->label($locale), 'description' => $plan->description($locale), 'prices' => $plan->prices];
        };
        // Whole amounts as "৳1,500" ("৳১,৫০০" in Bangla); anything with paisa or cents in the app's own way.
        $money = fn (int $minor, string $currency): string => $minor % 100 === 0
            ? (['BDT' => '৳', 'USD' => '$'][$currency] ?? $currency.' ').(new NumberFormatter($locale, NumberFormatter::DECIMAL))->format(intdiv($minor, 100))
            : MoneyText::shown($minor, $currency, $locale);

        $plans = [];
        foreach ($data['plans'] as $key) {
            $plan = $find($key);
            if ($plan === null) {
                continue;
            }
            $price = fn (string $period) => collect($plan['prices'])->first(fn ($row) => $row['currency'] === $data['currency'] && $row['period'] === $period);
            $monthly = $price('monthly');
            $yearly = $price('yearly');
            if ($monthly === null) {
                continue;
            }
            $saved = $yearly !== null && $monthly['amount_minor'] > 0 ? intdiv(12 * $monthly['amount_minor'] - $yearly['amount_minor'], $monthly['amount_minor']) : 0;
            $plans[] = [
                'key' => $key,
                'name' => $plan['name'],
                'description' => $plan['description'],
                // The platform's plans sign up in the app; a partner's plan is talked over first (its contact form).
                'url' => $own === null ? 'app:/signup?plan='.$key : '#write-to-us',
                'free' => $monthly['amount_minor'] === 0,
                'monthly' => $money((int) $monthly['amount_minor'], $data['currency']),
                'monthly_minor' => (int) $monthly['amount_minor'],
                'yearly' => $yearly === null ? null : $money((int) $yearly['amount_minor'], $data['currency']),
                'months_saved' => max(0, $saved),
                'highlight' => ($data['highlight'] ?? null) === $key,
                'features' => $data['features'][$key] ?? [],
            ];
        }

        return $plans;
    }

    /**
     * Search and sharing details: title, description, the one address
     * (canonical), the page in each language, and structured data (the
     * company, the software and its offers, the FAQ).
     *
     * @return array<string, mixed>
     */
    private function seo(array $snapshot, array $page, string $locale, string $origin, \Closure $tx, array $blocks, string $name, ?string $logo, ?string $shared): array
    {
        $main = $snapshot['default_locale'];
        $site = $snapshot['settings']['seo'] ?? [];
        $url = fn (string $code) => rtrim($origin, '/').($code === $main ? '' : '/'.$code).'/'.$page['slug'];
        $title = $tx($page['seo']['title'] ?? null) ?: ($page['is_home'] ? ($tx($site['title'] ?? null) ?: $name) : $tx($page['title']).' · '.$name);
        $description = $tx($page['seo']['description'] ?? null) ?: $tx($site['description'] ?? null);
        $image = $page['seo']['image_url'] ?? $shared ?? $site['image_url'] ?? $logo;
        $absolute = fn (?string $path) => $path === null ? null : (str_starts_with($path, 'http') ? $path : rtrim($origin, '/').$path);

        $graph = [['@type' => 'Organization', 'name' => $name, 'url' => rtrim($origin, '/').'/', 'logo' => $absolute($logo)]];
        $pricing = collect($blocks)->firstWhere('type', 'pricing');
        if ($pricing !== null) {
            $graph[] = [
                '@type' => 'SoftwareApplication', 'name' => $name, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
                'offers' => array_map(fn ($plan) => ['@type' => 'Offer', 'name' => $plan['name'], 'priceCurrency' => $pricing['data']['currency'], 'price' => intdiv($plan['monthly_minor'], 100).'.'.str_pad((string) ($plan['monthly_minor'] % 100), 2, '0', STR_PAD_LEFT)], $pricing['plans']),
            ];
        }
        $faq = collect($blocks)->firstWhere('type', 'faq');
        if ($faq !== null) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($item) => [
                '@type' => 'Question', 'name' => $tx($item['question']), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $tx($item['answer'])],
            ], $faq['data']['items'])];
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $url($locale),
            'alternates' => collect($snapshot['locales'])->mapWithKeys(fn ($code) => [$code => $url($code)])->put('x-default', $url($main))->all(),
            'image' => $absolute($image),
            'noindex' => (bool) ($page['seo']['noindex'] ?? false),
            // Inside <script>: < > & escaped as <… so nothing can end the element.
            'json_ld' => json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }
}
