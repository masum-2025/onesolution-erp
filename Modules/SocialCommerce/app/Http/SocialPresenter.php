<?php

namespace Modules\SocialCommerce\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Modules\Inventory\Services\Stock;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostMedia;
use Modules\SocialCommerce\Models\PostProduct;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Pages;
use Modules\SocialCommerce\Services\Publisher;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * Pages and posts as the screens see them. Never a token: photos are shown
 * through short-lived signed links, Facebook's errors as a translated line.
 */
class SocialPresenter
{
    /** Minutes a photo link works. */
    private const LINK_MINUTES = 30;

    public function __construct(private SocialBook $book, private Stock $stock, private Publisher $publisher) {}

    /**
     * @return array<string, mixed>
     */
    public function page(SocialPage $page): array
    {
        return [
            'id' => $page->getKey(),
            'page_id' => $page->page_id,
            'name' => $page->name,
            'picture_url' => $page->picture_url,
            'can_post' => $page->can_post,
            'auto_reply' => $page->auto_reply,
            'price_public' => $page->price_public,
            // Comments and messages reach us only when subscribed with every permission asked for.
            'hears' => $page->subscribed_at !== null && app(Pages::class)->missingScopes($page) === [],
            'status' => $page->status,
            'status_reason' => $page->status_reason,
            'connected_at' => $page->connected_at?->toIso8601String(),
            'connected_by' => $page->connected_by === null ? null : User::query()->whereKey($page->connected_by)->value('name'),
            'version' => $page->version,
        ];
    }

    /**
     * Posts for a list: the page, the first photo, the counts.
     *
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function list(Organization $company, Collection $posts): array
    {
        $ids = $posts->modelKeys();
        $pages = $this->book->query(SocialPage::class, $company)->whereIn('id', $posts->pluck('page_id')->unique()->all())->get()->keyBy(fn (SocialPage $page) => $page->getKey());
        $media = $this->book->query(PostMedia::class, $company)->whereIn('post_id', $ids)->orderBy('position')->get()->groupBy('post_id');
        $products = $this->book->query(PostProduct::class, $company)->whereIn('post_id', $ids)->get()->countBy('post_id');

        return $posts->map(fn (Post $post) => [
            ...$this->head($post),
            'page' => isset($pages[$post->page_id]) ? ['id' => $post->page_id, 'name' => $pages[$post->page_id]->name, 'picture_url' => $pages[$post->page_id]->picture_url] : null,
            'thumbnail' => ($first = $media->get($post->getKey())?->first()) === null ? null : $this->link($first),
            'photos' => $media->get($post->getKey())?->count() ?? 0,
            'products' => $products->get($post->getKey(), 0),
        ])->values()->all();
    }

    /**
     * One post with everything the post screen shows.
     *
     * @return array<string, mixed>
     */
    public function post(Organization $company, Post $post): array
    {
        $page = $this->book->query(SocialPage::class, $company)->find($post->page_id);
        $products = $this->book->query(PostProduct::class, $company)->where('post_id', $post->getKey())->orderBy('created_at')->orderBy('id')->get();
        $names = collect($this->stock->items($company, $products->pluck('item_id')->all()))->keyBy('id');
        $people = User::query()->whereKey(array_filter([$post->created_by, $post->approved_by, $post->rejected_by]))->pluck('name', 'id');

        return [
            ...$this->head($post),
            'page' => $page === null ? null : $this->page($page),
            'media' => $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->orderBy('position')->get()
                ->map(fn (PostMedia $media) => ['id' => $media->getKey(), 'url' => $this->link($media), 'width' => $media->width, 'height' => $media->height])->values()->all(),
            'products' => $products->map(fn (PostProduct $product) => [
                'item_id' => $product->item_id,
                'name' => $names[$product->item_id]['name'] ?? null,
                'price_minor' => $product->price_minor,
                'currency' => $product->currency_code,
            ])->values()->all(),
            // Exactly what Facebook gets (text and footer line).
            'final_message' => $this->publisher->message($company, $post),
            'created_by' => $people[$post->created_by] ?? null,
            'approved_by' => $people[$post->approved_by] ?? null,
            'rejected_by' => $people[$post->rejected_by] ?? null,
            'reject_reason' => $post->reject_reason,
            'submitted_at' => $post->submitted_at?->toIso8601String(),
            'approved_at' => $post->approved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function head(Post $post): array
    {
        $code = $post->error_code;

        return [
            'id' => $post->getKey(),
            'message' => $post->message,
            'locale' => $post->locale,
            'status' => $post->status,
            'publish_at' => $post->publish_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'fb_post_id' => $post->fb_post_id,
            'link' => $post->fb_post_id === null ? null : 'https://www.facebook.com/'.rawurlencode($post->fb_post_id),
            'error' => $code === null ? null : [
                'code' => $code,
                'text' => trans()->has("social_commerce::social.failures.{$code}") ? __("social_commerce::social.failures.{$code}") : __('social_commerce::social.failures.rejected'),
                'detail' => $post->error_message,
            ],
            'created_at' => $post->created_at?->toIso8601String(),
            'version' => $post->version,
        ];
    }

    private function link(PostMedia $media): string
    {
        return URL::temporarySignedRoute('social_commerce.media', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $media->organization_id,
            'media' => $media->getKey(),
        ], absolute: false);
    }
}
