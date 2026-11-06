<?php

namespace App\Platform\Marketing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Marketing\Exceptions\MarketingException;
use App\Platform\Marketing\Models\Block;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Redirect;
use App\Platform\Marketing\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Pages and their blocks (drafts: nothing shows until the site is
 * published). A page's address is lower-case words joined by "-"; the home
 * page stays. A page whose address changes leaves a redirect from the old
 * one, so links and search results keep working. Every change names the
 * version it was made on (base_version).
 */
class Pages
{
    /** Addresses the site itself answers (never a page). */
    public const RESERVED = ['bn', 'en', 'ar', 'sitemap.xml', 'robots.txt', 'fonts', 'brand', 'assets', 'preview', 'api', 'login', 'signup'];

    public function __construct(private BlockTypes $types, private AuditLogger $audit) {}

    public function find(Site $site, string $id): Page
    {
        return Page::query()->where('site_id', $site->getKey())->whereKey($id)->first() ?? throw MarketingException::pageNotFound();
    }

    public function findBlock(Site $site, string $id): Block
    {
        return Block::query()->where('site_id', $site->getKey())->whereKey($id)->first() ?? throw MarketingException::blockNotFound();
    }

    /** @param  array<string, mixed>  $data  Validated (PageRequest). */
    public function create(Site $site, array $data): Page
    {
        $this->checkSlug($site, $data['slug'], null);

        return Page::create([
            'site_id' => $site->getKey(), 'slug' => $data['slug'], 'is_home' => false, 'title' => $data['title'],
            'seo' => $data['seo'] ?? null, 'in_menu' => $data['in_menu'] ?? true,
            'sort_order' => (int) Page::query()->where('site_id', $site->getKey())->max('sort_order') + 1,
        ]);
    }

    /** @param  array<string, mixed>  $data  Validated (PageRequest). */
    public function update(Site $site, Page $page, array $data, int $baseVersion): Page
    {
        return DB::transaction(function () use ($site, $page, $data, $baseVersion) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->getKey());
            if ($page->version !== $baseVersion) {
                throw MarketingException::changedMeanwhile();
            }
            if ($page->is_home) {
                unset($data['slug']);
            }
            if (isset($data['slug']) && $data['slug'] !== $page->slug) {
                $this->checkSlug($site, $data['slug'], $page);
                Redirect::query()->updateOrCreate(['site_id' => $site->getKey(), 'from_path' => '/'.$page->slug], ['to_path' => '/'.$data['slug']]);
                Redirect::query()->where('site_id', $site->getKey())->where('from_path', '/'.$data['slug'])->delete();
            }
            $page->fill(array_intersect_key($data, array_flip(['slug', 'title', 'seo', 'in_menu'])));
            if ($page->isDirty()) {
                $page->version++;
                $page->save();
            }

            return $page;
        });
    }

    public function delete(Site $site, Page $page, User $actor): void
    {
        if ($page->is_home) {
            throw MarketingException::homeStays();
        }
        $page->delete();
        $this->audit->record(action: 'marketing.page_deleted', target: $site, old: ['slug' => $page->slug], actor: $actor, partnerId: $site->partner_id);
    }

    /** @param  list<string>  $ids  Every page of the site, in the new order. */
    public function reorder(Site $site, array $ids): void
    {
        DB::transaction(function () use ($site, $ids) {
            foreach (array_values($ids) as $order => $id) {
                Page::query()->where('site_id', $site->getKey())->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    /** @param  array<string, mixed>  $input  Validated (BlockRequest): type, data, visibility. */
    public function addBlock(Site $site, Page $page, array $input): Block
    {
        $data = $this->types->clean($input['type'], (array) $input['data'], $site->default_locale, $site->partner);
        MediaLibrary::checkOwned($site, $data);

        return Block::create([
            'site_id' => $site->getKey(), 'page_id' => $page->getKey(), 'type' => $input['type'],
            'data' => $data,
            'sort_order' => (int) Block::query()->where('page_id', $page->getKey())->max('sort_order') + 1,
            'is_visible' => $input['is_visible'] ?? true,
            'visible_from' => $input['visible_from'] ?? null,
            'visible_until' => $input['visible_until'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $input  Validated (BlockRequest): data, visibility. */
    public function updateBlock(Site $site, Block $block, array $input, int $baseVersion): Block
    {
        return DB::transaction(function () use ($site, $block, $input, $baseVersion) {
            $block = Block::query()->lockForUpdate()->findOrFail($block->getKey());
            if ($block->version !== $baseVersion) {
                throw MarketingException::changedMeanwhile();
            }
            if (array_key_exists('data', $input)) {
                $block->data = $this->types->clean($block->type, (array) $input['data'], $site->default_locale, $site->partner);
                MediaLibrary::checkOwned($site, $block->data);
            }
            $block->fill(array_intersect_key($input, array_flip(['is_visible', 'visible_from', 'visible_until'])));
            if ($block->isDirty()) {
                $block->version++;
                $block->save();
            }

            return $block;
        });
    }

    public function deleteBlock(Block $block): void
    {
        $block->delete();
    }

    /** @param  list<string>  $ids  Every block of the page, in the new order. */
    public function reorderBlocks(Page $page, array $ids): void
    {
        DB::transaction(function () use ($page, $ids) {
            foreach (array_values($ids) as $order => $id) {
                Block::query()->where('page_id', $page->getKey())->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    private function checkSlug(Site $site, string $slug, ?Page $page): void
    {
        if (in_array($slug, self::RESERVED, true)) {
            throw MarketingException::slugReserved();
        }
        $taken = Page::query()->where('site_id', $site->getKey())->where('slug', $slug)
            ->when($page !== null, fn ($query) => $query->whereKeyNot($page->getKey()))->exists();
        if ($taken) {
            throw MarketingException::slugTaken();
        }
    }
}
