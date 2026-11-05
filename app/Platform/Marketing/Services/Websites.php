<?php

namespace App\Platform\Marketing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Marketing\Exceptions\MarketingException;
use App\Platform\Marketing\Models\Block;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Models\Version;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * A partner's company website as a whole: made from a starter, its settings
 * changed (one change at a time: base_version), published as a snapshot the
 * public sees (drafts never show), an earlier publish brought back, taken
 * offline. Publishing, settings, going back and going offline are audited.
 */
class Websites
{
    public function __construct(
        private AuditLogger $audit,
        private SiteSettings $settings,
        private StarterContent $starter,
    ) {}

    public function of(Partner $partner): ?Site
    {
        return Site::query()->where('partner_id', $partner->getKey())->first();
    }

    public function require(Partner $partner): Site
    {
        return $this->of($partner) ?? throw MarketingException::noSite();
    }

    /** A new site from the starter (the house partner's tells about the platform; others' are a short general start). */
    public function create(Partner $partner, User $actor): Site
    {
        if ($this->of($partner) !== null) {
            throw MarketingException::siteExists();
        }

        return DB::transaction(function () use ($partner, $actor) {
            $start = $this->starter->for($partner);
            $site = Site::create([
                'partner_id' => $partner->getKey(),
                'template' => $start['template'],
                'settings' => $this->settings->clean($start['settings'], $start['default_locale']),
                'locales' => $start['locales'],
                'default_locale' => $start['default_locale'],
                'status' => Site::DRAFT,
                'version' => 1,
            ]);
            foreach ($start['pages'] as $order => $page) {
                $row = Page::create([
                    'site_id' => $site->getKey(), 'slug' => $page['slug'], 'is_home' => $page['slug'] === '',
                    'title' => $page['title'], 'seo' => $page['seo'] ?? null, 'in_menu' => $page['in_menu'] ?? true, 'sort_order' => $order,
                ]);
                foreach ($page['blocks'] as $index => [$type, $data]) {
                    Block::create([
                        'site_id' => $site->getKey(), 'page_id' => $row->getKey(), 'type' => $type, 'sort_order' => $index,
                        'data' => app(BlockTypes::class)->clean($type, $data, $start['default_locale']),
                    ]);
                }
            }
            $this->audit->record(action: 'marketing.site_created', target: $site, new: ['template' => $site->template], actor: $actor, partnerId: $partner->getKey());

            return $site;
        });
    }

    /**
     * Template, languages, main language and settings.
     *
     * @param  array<string, mixed>  $changes  Validated (UpdateWebsiteRequest); settings checked here.
     */
    public function update(Site $site, array $changes, int $baseVersion, User $actor): Site
    {
        return DB::transaction(function () use ($site, $changes, $baseVersion, $actor) {
            $site = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            if ($site->version !== $baseVersion) {
                throw MarketingException::changedMeanwhile();
            }
            $locales = $changes['locales'] ?? $site->locales;
            $main = $changes['default_locale'] ?? $site->default_locale;
            if (! in_array($main, $locales, true)) {
                throw MarketingException::mainLocaleMissing();
            }
            if (array_key_exists('settings', $changes)) {
                $changes['settings'] = $this->settings->clean((array) $changes['settings'], $main);
            }
            $site->fill(array_intersect_key($changes, array_flip(['template', 'locales', 'default_locale', 'settings'])));
            if (! $site->isDirty()) {
                return $site;
            }
            $old = array_intersect_key($site->getOriginal(), $site->getDirty());
            $site->version++;
            $site->save();
            $this->audit->record(
                action: 'marketing.site_updated', target: $site,
                old: array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $old),
                new: array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, array_intersect_key($site->getAttributes(), $old)),
                actor: $actor, partnerId: $site->partner_id,
            );

            return $site;
        });
    }

    /** What the public will see: the drafts as they are now. */
    public function snapshot(Site $site): array
    {
        $pages = Page::query()->where('site_id', $site->getKey())->orderBy('sort_order')->get();
        $blocks = Block::query()->where('site_id', $site->getKey())->orderBy('sort_order')->get()->groupBy('page_id');

        return [
            'template' => $site->template,
            'settings' => $site->settings,
            'locales' => $site->locales,
            'default_locale' => $site->default_locale,
            'pages' => $pages->map(fn (Page $page) => [
                'id' => $page->getKey(),
                'slug' => $page->slug,
                'is_home' => $page->is_home,
                'title' => $page->title,
                'seo' => $page->seo ?? [],
                'in_menu' => $page->in_menu,
                'blocks' => ($blocks[$page->getKey()] ?? collect())->filter(fn (Block $block) => $block->is_visible)->map(fn (Block $block) => [
                    'id' => $block->getKey(),
                    'type' => $block->type,
                    'data' => $block->data,
                    'visible_from' => $block->visible_from?->toDateString(),
                    'visible_until' => $block->visible_until?->toDateString(),
                ])->values()->all(),
            ])->all(),
        ];
    }

    public function publish(Site $site, User $actor): Site
    {
        return DB::transaction(function () use ($site, $actor) {
            $site = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $snapshot = $this->snapshot($site);
            if (collect($snapshot['pages'])->firstWhere('is_home', true) === null) {
                throw MarketingException::nothingToPublish();
            }
            $site->forceFill([
                'published' => $snapshot, 'published_at' => now(), 'published_by' => $actor->getKey(),
                'status' => Site::PUBLISHED, 'cache_version' => $site->cache_version + 1,
            ])->save();
            Version::create(['site_id' => $site->getKey(), 'snapshot' => $snapshot, 'published_by' => $actor->getKey(), 'published_at' => now()]);
            $keep = Version::query()->where('site_id', $site->getKey())->orderByDesc('published_at')->orderByDesc('id')
                ->skip((int) config('marketing.versions_kept'))->take(1000)->pluck('id');
            Version::query()->whereIn('id', $keep)->delete();
            $this->audit->record(action: 'marketing.site_published', target: $site, new: ['pages' => count($snapshot['pages'])], actor: $actor, partnerId: $site->partner_id);

            return $site;
        });
    }

    /** An earlier publish goes live again, and the drafts become that version (to go on from there). */
    public function restore(Site $site, string $versionId, User $actor): Site
    {
        return DB::transaction(function () use ($site, $versionId, $actor) {
            $version = Version::query()->where('site_id', $site->getKey())->whereKey($versionId)->first() ?? throw MarketingException::versionNotFound();
            $snapshot = $version->snapshot;
            Page::query()->where('site_id', $site->getKey())->delete();
            foreach ($snapshot['pages'] as $order => $page) {
                $row = Page::create([
                    'site_id' => $site->getKey(), 'slug' => $page['slug'], 'is_home' => $page['is_home'], 'title' => $page['title'],
                    'seo' => $page['seo'] ?: null, 'in_menu' => $page['in_menu'], 'sort_order' => $order,
                ]);
                foreach ($page['blocks'] as $index => $block) {
                    Block::create([
                        'site_id' => $site->getKey(), 'page_id' => $row->getKey(), 'type' => $block['type'], 'data' => $block['data'], 'sort_order' => $index,
                        'visible_from' => $block['visible_from'], 'visible_until' => $block['visible_until'],
                    ]);
                }
            }
            $site->refresh()->forceFill([
                'template' => $snapshot['template'], 'settings' => $snapshot['settings'], 'locales' => $snapshot['locales'], 'default_locale' => $snapshot['default_locale'],
            ]);
            $site->forceFill([
                'published' => $this->snapshot($site), 'published_at' => now(), 'published_by' => $actor->getKey(),
                'status' => Site::PUBLISHED, 'cache_version' => $site->cache_version + 1, 'version' => $site->version + 1,
            ])->save();
            $this->audit->record(action: 'marketing.site_restored', target: $site, new: ['version' => $version->getKey()], actor: $actor, partnerId: $site->partner_id);

            return $site;
        });
    }

    public function takeOffline(Site $site, User $actor): Site
    {
        $site->forceFill(['status' => Site::OFFLINE, 'cache_version' => $site->cache_version + 1])->save();
        $this->audit->record(action: 'marketing.site_offline', target: $site, actor: $actor, partnerId: $site->partner_id);

        return $site;
    }

    /** A signed link on the app's host that shows the drafts for an hour (never indexed; "page" and "lang" may change). */
    public function previewUrl(Site $site): string
    {
        return URL::temporarySignedRoute('marketing.preview', now()->addHour(), ['site' => $site->getKey()]);
    }
}
