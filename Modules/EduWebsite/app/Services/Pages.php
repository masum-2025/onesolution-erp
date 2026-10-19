<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;

/**
 * The draft pages of a website and their blocks. At most
 * edu_website.max_pages pages; one home page that cannot be removed;
 * blocks of known types only, their data checked (BlockTypes) and their
 * pictures and files the site's own. Nothing here reaches the public until
 * the site is published.
 */
class Pages
{
    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /**
     * @param  array{slug: string, title: array<string, string>, in_menu?: bool}  $data
     */
    public function create(Organization $company, Site $site, array $data): Page
    {
        return $this->place->transaction($company, function () use ($company, $site, $data) {
            $max = (int) $this->rules->get('edu_website.max_pages', $this->contexts->forOrganization($company));
            $count = $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->lockForUpdate()->count();
            if ($count >= $max) {
                throw WebsiteException::tooManyPages($max);
            }
            $this->slugFree($company, $site, $data['slug']);
            $page = new Page;
            $page->fill(['organization_id' => $company->getKey(), 'site_id' => $site->getKey(), 'slug' => $data['slug'], 'sort_order' => $count, 'in_menu' => $data['in_menu'] ?? true, 'is_home' => false, 'version' => 1]);
            $page->putTexts('title', $data['title']);
            $page->save();

            return $page;
        });
    }

    /**
     * @param  array<string, mixed>  $data  slug, title, in_menu, sort_order; base_version
     */
    public function update(Organization $company, Page $page, array $data): Page
    {
        return $this->place->transaction($company, function () use ($company, $page, $data) {
            $page = $this->freshPage($company, $page, (int) $data['base_version']);
            if (isset($data['slug']) && $data['slug'] !== $page->slug) {
                if ($page->is_home) {
                    throw WebsiteException::homeNeeded();
                }
                $this->slugFree($company, $this->site($company, $page), $data['slug'], $page->getKey());
                $page->slug = $data['slug'];
            }
            $page->fill(array_intersect_key($data, array_flip(['in_menu', 'sort_order'])));
            if (isset($data['title'])) {
                $page->putTexts('title', $data['title']);
            }
            $page->forceFill(['version' => $page->version + 1])->save();

            return $page;
        });
    }

    public function remove(Organization $company, Page $page, int $baseVersion): void
    {
        $this->place->transaction($company, function () use ($company, $page, $baseVersion) {
            $page = $this->freshPage($company, $page, $baseVersion);
            if ($page->is_home) {
                throw WebsiteException::homeNeeded();
            }
            $this->place->query(Block::class, $company)->where('page_id', $page->getKey())->delete();
            $page->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data  The block's data (checked here).
     */
    public function addBlock(Organization $company, Page $page, string $type, array $data): Block
    {
        $clean = $this->checked($company, $page, $type, $data);

        return $this->place->transaction($company, function () use ($company, $page, $type, $clean) {
            $order = (int) $this->place->query(Block::class, $company)->where('page_id', $page->getKey())->max('sort_order') + 1;
            $block = new Block;
            $block->fill(['organization_id' => $company->getKey(), 'page_id' => $page->getKey(), 'type' => $type, 'sort_order' => $order, 'data' => $clean, 'version' => 1])->save();

            return $block;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBlock(Organization $company, Block $block, array $data, int $baseVersion): Block
    {
        $page = $this->place->query(Page::class, $company)->whereKey($block->page_id)->firstOrFail();
        $clean = $this->checked($company, $page, $block->type, $data);

        return $this->place->transaction($company, function () use ($company, $block, $clean, $baseVersion) {
            $block = $this->freshBlock($company, $block, $baseVersion);
            $block->forceFill(['data' => $clean, 'version' => $block->version + 1])->save();

            return $block;
        });
    }

    public function removeBlock(Organization $company, Block $block, int $baseVersion): void
    {
        $this->place->transaction($company, fn () => $this->freshBlock($company, $block, $baseVersion)->delete());
    }

    /**
     * Put a page's blocks in the order given (ids of that page only).
     *
     * @param  list<string>  $ids
     */
    public function order(Organization $company, Page $page, array $ids): void
    {
        $this->place->transaction($company, function () use ($company, $page, $ids) {
            $blocks = $this->place->query(Block::class, $company)->where('page_id', $page->getKey())->get()->keyBy(fn (Block $block) => $block->getKey());
            foreach (array_values(array_unique($ids)) as $index => $id) {
                if (isset($blocks[$id])) {
                    $blocks[$id]->forceFill(['sort_order' => $index])->save();
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(Organization $company, Page $page, string $type, array $data): array
    {
        $clean = BlockTypes::clean($type, $data);
        $wanted = BlockTypes::mediaIds($type, $clean);
        if ($wanted !== []) {
            $found = $this->place->query(Media::class, $company)->where('site_id', $page->site_id)->whereKey($wanted)->count();
            if ($found !== count(array_unique($wanted))) {
                throw WebsiteException::mediaNotFound();
            }
        }

        return $clean;
    }

    private function slugFree(Organization $company, Site $site, string $slug, ?string $except = null): void
    {
        $taken = $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->where('slug', $slug)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists();
        if ($taken) {
            throw WebsiteException::slugTaken();
        }
    }

    private function site(Organization $company, Page $page): Site
    {
        return $this->place->query(Site::class, $company)->whereKey($page->site_id)->firstOrFail();
    }

    private function freshPage(Organization $company, Page $page, int $baseVersion): Page
    {
        /** @var Page $fresh */
        $fresh = $this->place->query(Page::class, $company)->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw WebsiteException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private function freshBlock(Organization $company, Block $block, int $baseVersion): Block
    {
        /** @var Block $fresh */
        $fresh = $this->place->query(Block::class, $company)->whereKey($block->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw WebsiteException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
