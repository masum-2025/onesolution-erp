<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Sites\SiteAddress;
use App\Platform\Sites\SiteResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;

/**
 * A company's website: starting it (an address, a template the rules
 * allow, a home page with a few blocks), its settings, publishing (a
 * snapshot of the draft pages; the public sees only that) and taking it
 * offline (content kept). Every step that changes what the public sees is
 * audited.
 */
class Sites
{
    public const MODULE = 'edu_website';

    /** The templates there are (the rule edu_website.templates says which are offered). */
    public const TEMPLATES = ['school', 'minimal', 'premium'];

    public function __construct(
        private Place $place,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private SiteResolver $resolver,
        private AuditLogger $audit,
    ) {}

    public function of(Organization $company): ?Site
    {
        return $this->place->query(Site::class, $company)->first();
    }

    /** @return list<string> */
    public function templates(Organization $company): array
    {
        return array_values((array) $this->rules->get('edu_website.templates', $this->contexts->forOrganization($company)));
    }

    /**
     * @param  array{slug: string, title: array<string, string>, template: string, default_locale: string}  $data
     */
    public function start(Organization $company, array $data, User $actor): Site
    {
        if (! in_array($data['template'], $this->templates($company), true)) {
            throw WebsiteException::templateClosed();
        }
        $slug = strtolower($data['slug']);

        return $this->place->transaction($company, function () use ($company, $data, $slug, $actor) {
            $this->claim($company, $slug);
            $site = new Site;
            $site->fill([
                'organization_id' => $company->getKey(), 'slug' => $slug, 'template' => $data['template'],
                'theme' => ['primary' => '#1d4ed8', 'accent' => '#f59e0b'], 'locales' => ['bn', 'en'],
                'default_locale' => $data['default_locale'], 'status' => Site::DRAFT, 'version' => 1,
            ]);
            $site->putTexts('title', $data['title']);
            $site->save();

            $home = new Page;
            $home->fill(['organization_id' => $company->getKey(), 'site_id' => $site->getKey(), 'slug' => 'home', 'sort_order' => 0, 'in_menu' => true, 'is_home' => true, 'version' => 1]);
            $home->putTexts('title', ['en' => 'Home', 'bn' => 'প্রথম পাতা']);
            $home->save();
            foreach ([
                ['hero', ['title' => $data['title']]],
                ['notices', ['title' => ['en' => 'Notices', 'bn' => 'নোটিশ'], 'count' => 5]],
                ['contact', ['title' => ['en' => 'Contact', 'bn' => 'যোগাযোগ'], 'show_map' => true]],
            ] as $index => [$type, $blockData]) {
                (new Block)->fill(['organization_id' => $company->getKey(), 'page_id' => $home->getKey(), 'type' => $type, 'sort_order' => $index, 'data' => $blockData, 'version' => 1])->save();
            }
            $this->audit->record('edu_website.started', $site, new: ['slug' => $slug, 'template' => $site->template], actor: $actor, organizationId: $company->getKey());

            return $site;
        });
    }

    /**
     * @param  array<string, mixed>  $data  slug, title, tagline, template, theme, contact, default_locale, logo_media_id; base_version
     */
    public function update(Organization $company, Site $site, array $data, User $actor): Site
    {
        if (isset($data['template']) && ! in_array($data['template'], $this->templates($company), true)) {
            throw WebsiteException::templateClosed();
        }

        return $this->place->transaction($company, function () use ($company, $site, $data, $actor) {
            $site = $this->fresh($company, $site, (int) ($data['base_version'] ?? 0));
            $old = ['slug' => $site->slug, 'template' => $site->template];
            if (isset($data['slug']) && strtolower($data['slug']) !== $site->slug) {
                $this->claim($company, strtolower($data['slug']), $site->slug);
                $site->slug = strtolower($data['slug']);
            }
            $site->fill(array_intersect_key($data, array_flip(['template', 'theme', 'contact', 'default_locale', 'locales', 'logo_media_id', 'share_media_id'])));
            if (! in_array($site->default_locale, $site->locales ?? [], true)) {
                throw WebsiteException::defaultLocaleOff();
            }
            foreach (['title', 'tagline'] as $field) {
                if (array_key_exists($field, $data)) {
                    $site->putTexts($field, $data[$field]);
                }
            }
            $site->forceFill(['version' => $site->version + 1, 'cache_version' => $site->cache_version + 1])->save();
            $this->audit->record('edu_website.settings_changed', $site, old: $old, new: ['slug' => $site->slug, 'template' => $site->template], actor: $actor, organizationId: $company->getKey());

            return $site;
        });
    }

    /** The draft pages and blocks become what the public sees. */
    public function publish(Organization $company, Site $site, int $baseVersion, User $actor): Site
    {
        return $this->place->transaction($company, function () use ($company, $site, $baseVersion, $actor) {
            $site = $this->fresh($company, $site, $baseVersion);
            $snapshot = $this->snapshot($company, $site);
            if (! collect($snapshot)->contains(fn (array $page) => $page['is_home'])) {
                throw WebsiteException::homeNeeded();
            }

            $site->forceFill([
                'published' => ['pages' => $snapshot], 'published_at' => now(), 'status' => Site::PUBLISHED,
                'version' => $site->version + 1, 'cache_version' => $site->cache_version + 1,
            ])->save();
            $this->audit->record('edu_website.published', $site, new: ['pages' => count($snapshot)], actor: $actor, organizationId: $company->getKey());

            return $site;
        });
    }

    /** The public sees "not available" until the next publish; nothing is deleted. */
    public function takeOffline(Organization $company, Site $site, int $baseVersion, User $actor): Site
    {
        return $this->place->transaction($company, function () use ($company, $site, $baseVersion, $actor) {
            $site = $this->fresh($company, $site, $baseVersion);
            $site->forceFill(['status' => Site::OFFLINE, 'version' => $site->version + 1, 'cache_version' => $site->cache_version + 1])->save();
            $this->audit->record('edu_website.taken_offline', $site, actor: $actor, organizationId: $company->getKey());

            return $site;
        });
    }

    /**
     * The draft pages and blocks as publishing keeps them.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(Organization $company, Site $site): array
    {
        $pages = $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->orderBy('sort_order')->get();
        $blocks = $this->place->query(Block::class, $company)->whereIn('page_id', $pages->modelKeys())->orderBy('sort_order')->get()->groupBy('page_id');

        return $pages->map(fn (Page $page) => [
            'slug' => $page->slug, 'title' => $page->texts('title'), 'in_menu' => $page->in_menu, 'is_home' => $page->is_home,
            // Search and sharing, only when set (snapshots published before them stay equal).
            ...array_filter(['description' => $page->texts('description'), 'share_media_id' => $page->share_media_id]),
            'blocks' => $blocks->get($page->getKey(), collect())->map(fn (Block $block) => ['type' => $block->type, 'data' => $block->data])->values()->all(),
        ])->values()->all();
    }

    /** The draft pages differ from what was last published (never published counts as changed). */
    public function hasChanges(Organization $company, Site $site): bool
    {
        return $this->snapshot($company, $site) != ($site->published['pages'] ?? null);
    }

    /** Something the public sees changed (a notice): pages are built afresh. */
    public function touch(Organization $company, string $siteId): void
    {
        $this->place->query(Site::class, $company)->whereKey($siteId)->increment('cache_version');
    }

    private function fresh(Organization $company, Site $site, int $baseVersion): Site
    {
        /** @var Site $fresh */
        $fresh = $this->place->query(Site::class, $company)->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw WebsiteException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /** Take a slug in the central list of site addresses (refused when another site has it). */
    private function claim(Organization $company, string $slug, ?string $previous = null): void
    {
        DB::transaction(function () use ($company, $slug, $previous) {
            $taken = SiteAddress::query()->where('slug', $slug)->where('organization_id', '!=', $company->getKey())->lockForUpdate()->exists();
            if ($taken) {
                throw WebsiteException::slugTaken();
            }
            SiteAddress::query()->updateOrCreate(['organization_id' => $company->getKey(), 'module' => self::MODULE], ['slug' => $slug]);
        });
        $this->resolver->forget($slug);
        if ($previous !== null) {
            $this->resolver->forget($previous);
        }
    }
}
