<?php

namespace Modules\EduWebsite\Http;

use App\Platform\Sites\SiteResolver;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;

/** Website records as the admin API shows them. */
class WebsitePresenter
{
    public function __construct(private SiteResolver $resolver) {}

    /**
     * @return array<string, mixed>
     */
    public function site(Site $site): array
    {
        return [
            'id' => $site->getKey(), 'slug' => $site->slug, 'template' => $site->template, 'theme' => $site->theme,
            'logo_media_id' => $site->logo_media_id, 'title' => $site->texts('title'), 'tagline' => $site->texts('tagline'),
            'contact' => $site->contact ?? [], 'locales' => $site->locales, 'default_locale' => $site->default_locale,
            'status' => $site->status, 'published_at' => $site->published_at?->toIso8601String(), 'version' => $site->version,
            'addresses' => ['site' => $this->resolver->url($site->slug), 'path' => url('/s/'.$site->slug)],
        ];
    }

    /**
     * @param  iterable<Block>  $blocks
     * @return array<string, mixed>
     */
    public function page(Page $page, ?iterable $blocks = null): array
    {
        return [
            'id' => $page->getKey(), 'slug' => $page->slug, 'title' => $page->texts('title'), 'sort_order' => $page->sort_order,
            'in_menu' => $page->in_menu, 'is_home' => $page->is_home, 'version' => $page->version,
            ...($blocks === null ? [] : ['blocks' => collect($blocks)->map(fn (Block $block) => $this->block($block))->values()->all()]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function block(Block $block): array
    {
        return ['id' => $block->getKey(), 'page_id' => $block->page_id, 'type' => $block->type, 'sort_order' => $block->sort_order, 'data' => $block->data, 'version' => $block->version];
    }

    /**
     * @return array<string, mixed>
     */
    public function notice(Notice $notice): array
    {
        return [
            'id' => $notice->getKey(), 'title' => $notice->texts('title'), 'body' => $notice->texts('body'), 'media_id' => $notice->media_id,
            'published_on' => $notice->published_on->toDateString(), 'pinned' => $notice->pinned, 'is_published' => $notice->is_published, 'version' => $notice->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(Media $media, string $slug): array
    {
        $base = url('/s/'.$slug.'/media/'.$media->getKey());

        return [
            'id' => $media->getKey(), 'kind' => $media->kind, 'name' => $media->name, 'size_bytes' => $media->size_bytes,
            'width' => $media->width, 'height' => $media->height,
            'url' => $media->kind === 'pdf' ? "{$base}/file" : "{$base}/large", 'thumb' => $media->kind === 'pdf' ? null : "{$base}/small",
        ];
    }
}
