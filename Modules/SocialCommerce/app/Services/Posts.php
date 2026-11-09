<?php

namespace Modules\SocialCommerce\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Services\Stock;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Jobs\PublishPost;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostMedia;
use Modules\SocialCommerce\Models\PostProduct;
use Modules\SocialCommerce\Models\SocialPage;

/**
 * Facebook posts of a company (public service of the module).
 *
 * A post is written as a draft (text, photos, products), sent on, and —
 * where social_commerce.posts_need_approval is on and the writer may not
 * approve — waits for someone with social_commerce.approve. Approved, it
 * goes out at its time (or at once) through PublishPost. A post turned
 * down or one Facebook refused can be changed and sent again. Every step
 * is audited; writes carry base_version, a new post an op_id.
 */
class Posts
{
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Minutes a post may sit in "publishing" before it counts as interrupted. */
    public const STUCK_MINUTES = 15;

    public function __construct(
        private SocialBook $book,
        private Stock $stock,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private ModuleResolver $modules,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{page_id: string, message?: ?string, locale: string, publish_at?: ?string, item_ids?: list<string>, op_id?: ?string}  $data
     */
    public function create(Organization $company, User $actor, array $data): Post
    {
        $opId = $data['op_id'] ?? null;
        if ($opId !== null && ($done = $this->book->query(Post::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        $page = $this->pageOf($company, $data['page_id']);
        $products = $this->products($company, $data['item_ids'] ?? []);

        return $this->book->transaction($company, function () use ($company, $actor, $data, $page, $products, $opId) {
            $post = $this->book->query(Post::class, $company)->create([
                'organization_id' => $company->getKey(),
                'page_id' => $page->getKey(),
                'message' => $this->clean($data['message'] ?? null),
                'locale' => $data['locale'],
                'status' => Post::DRAFT,
                'publish_at' => $this->when($data['publish_at'] ?? null),
                'created_by' => $actor->getKey(),
                'op_id' => $opId,
                'version' => 1,
            ]);
            $this->keepProducts($company, $post, $products);
            $this->audit->record(action: 'social_commerce.post_written', target: $post, new: ['page' => $page->name, 'products' => count($products)], actor: $actor, organizationId: $company->getKey());

            return $post;
        });
    }

    /**
     * @param  array{page_id?: string, message?: ?string, locale?: string, publish_at?: ?string, item_ids?: list<string>}  $data
     */
    public function revise(Organization $company, Post $post, int $baseVersion, array $data, User $actor): Post
    {
        $page = isset($data['page_id']) ? $this->pageOf($company, $data['page_id']) : null;
        $products = array_key_exists('item_ids', $data) ? $this->products($company, $data['item_ids'] ?? []) : null;

        return $this->locked($company, $post, $baseVersion, 'edit', Post::EDITABLE, function (Post $post) use ($company, $data, $page, $products, $actor) {
            $changes = [];
            if ($page !== null) {
                $changes['page_id'] = $page->getKey();
            }
            if (array_key_exists('message', $data)) {
                $changes['message'] = $this->clean($data['message']);
            }
            if (isset($data['locale'])) {
                $changes['locale'] = $data['locale'];
            }
            if (array_key_exists('publish_at', $data)) {
                $changes['publish_at'] = $this->when($data['publish_at']);
            }
            $post->fill($changes);
            if ($products !== null) {
                $this->book->query(PostProduct::class, $company)->where('post_id', $post->getKey())->delete();
                $this->keepProducts($company, $post, $products);
            }
            $this->audit->record(action: 'social_commerce.post_changed', target: $post, new: array_keys($changes + ($products !== null ? ['products' => 1] : [])), actor: $actor, organizationId: $company->getKey());
        });
    }

    /** A photo for the post (jpeg, png or webp, up to the rule's size), last in order. */
    public function addMedia(Organization $company, Post $post, int $baseVersion, UploadedFile $file, User $actor): Post
    {
        $context = $this->contexts->forOrganization($company);
        $max = (int) $this->rules->get('social_commerce.max_images_per_post', $context);
        $mb = (int) $this->rules->get('social_commerce.max_image_mb', $context);
        $extension = self::IMAGE_TYPES[(string) $file->getMimeType()] ?? null;
        // The request checked type and size; this checks the content itself.
        $size = $extension === null ? false : @getimagesize((string) $file->getRealPath());
        if ($size === false) {
            throw SocialCommerceException::notAnImage();
        }
        if ($file->getSize() > $mb * 1024 * 1024) {
            throw SocialCommerceException::imageTooLarge($mb);
        }

        return $this->locked($company, $post, $baseVersion, 'edit', Post::EDITABLE, function (Post $post) use ($company, $file, $extension, $size, $max, $actor) {
            $count = $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->count();
            if ($count >= $max) {
                throw SocialCommerceException::tooManyImages($max);
            }
            $path = $file->storeAs("social/{$company->getKey()}/{$post->getKey()}", Str::lower((string) Str::ulid()).'.'.$extension, 'local');
            $this->book->query(PostMedia::class, $company)->create([
                'organization_id' => $company->getKey(),
                'post_id' => $post->getKey(),
                'position' => (int) $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->max('position') + 1,
                'path' => $path,
                'mime' => (string) $file->getMimeType(),
                'bytes' => (int) $file->getSize(),
                'width' => (int) $size[0],
                'height' => (int) $size[1],
            ]);
            $this->audit->record(action: 'social_commerce.photo_added', target: $post, new: ['bytes' => (int) $file->getSize(), 'type' => $extension], actor: $actor, organizationId: $company->getKey());
        });
    }

    public function removeMedia(Organization $company, Post $post, int $baseVersion, string $mediaId, User $actor): Post
    {
        return $this->locked($company, $post, $baseVersion, 'edit', Post::EDITABLE, function (Post $post) use ($company, $mediaId, $actor) {
            $media = $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->whereKey($mediaId)->first()
                ?? throw SocialCommerceException::notFound('photo');
            Storage::disk('local')->delete($media->path);
            $media->delete();
            $this->audit->record(action: 'social_commerce.photo_removed', target: $post, actor: $actor, organizationId: $company->getKey());
        });
    }

    /**
     * Puts the photos in this order (every photo of the post, once).
     *
     * @param  list<string>  $mediaIds
     */
    public function reorderMedia(Organization $company, Post $post, int $baseVersion, array $mediaIds, User $actor): Post
    {
        return $this->locked($company, $post, $baseVersion, 'edit', Post::EDITABLE, function (Post $post) use ($company, $mediaIds) {
            $media = $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->get()->keyBy(fn (PostMedia $m) => $m->getKey());
            if (count($mediaIds) !== $media->count() || array_diff($mediaIds, $media->keys()->all()) !== []) {
                throw SocialCommerceException::notFound('photo');
            }
            foreach (array_values($mediaIds) as $index => $id) {
                $media[$id]->update(['position' => $index + 1]);
            }
        });
    }

    /** Sent on: to the approvers, or (no approval needed, or the writer may approve) straight to its time. */
    public function submit(Organization $company, Post $post, int $baseVersion, User $actor): Post
    {
        $context = $this->contexts->forOrganization($company);
        $needsApproval = $this->rules->get('social_commerce.posts_need_approval', $context) === true
            && ! Gate::forUser($actor)->allows('social_commerce.approve', $company);

        $post = $this->locked($company, $post, $baseVersion, 'submit', Post::EDITABLE, function (Post $post) use ($company, $actor, $needsApproval) {
            $this->assertReady($company, $post);
            $post->forceFill([
                'status' => $needsApproval ? Post::PENDING : Post::SCHEDULED,
                'submitted_at' => CarbonImmutable::now(),
                'approved_by' => $needsApproval ? null : $actor->getKey(),
                'approved_at' => $needsApproval ? null : CarbonImmutable::now(),
                'rejected_by' => null,
                'reject_reason' => null,
                'error_code' => null,
                'error_message' => null,
                'attempts' => 0,
            ]);
            $this->audit->record(action: $needsApproval ? 'social_commerce.post_sent_for_approval' : 'social_commerce.post_scheduled', target: $post, new: ['publish_at' => $post->publish_at?->toIso8601String()], actor: $actor, organizationId: $company->getKey());
        });

        if ($needsApproval) {
            $page = $this->book->query(SocialPage::class, $company)->find($post->page_id);
            $this->notifier->notify('social_commerce.post_waiting', $this->recipients->holding('social_commerce.approve', $company)->reject(fn (User $user) => $user->getKey() === $actor->getKey()), fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'author' => (string) $actor->name,
                'page' => (string) $page?->name,
            ], $company->partner, $company);
        } else {
            $this->sendIfDue($company, $post);
        }

        return $post;
    }

    public function approve(Organization $company, Post $post, int $baseVersion, User $actor): Post
    {
        $post = $this->locked($company, $post, $baseVersion, 'approve', [Post::PENDING], function (Post $post) use ($company, $actor) {
            $this->assertReady($company, $post, checkTime: false);
            $post->forceFill(['status' => Post::SCHEDULED, 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now()]);
            $this->audit->record(action: 'social_commerce.post_approved', target: $post, new: ['publish_at' => $post->publish_at?->toIso8601String()], actor: $actor, organizationId: $company->getKey());
        });
        $this->sendIfDue($company, $post);

        return $post;
    }

    public function reject(Organization $company, Post $post, int $baseVersion, string $reason, User $actor): Post
    {
        return $this->locked($company, $post, $baseVersion, 'reject', [Post::PENDING], function (Post $post) use ($company, $reason, $actor) {
            $post->forceFill(['status' => Post::REJECTED, 'rejected_by' => $actor->getKey(), 'reject_reason' => $reason]);
            $this->audit->record(action: 'social_commerce.post_rejected', target: $post, reason: $reason, actor: $actor, organizationId: $company->getKey());
        });
    }

    /** Back to a draft before it goes out (no longer waiting, no longer scheduled). */
    public function withdraw(Organization $company, Post $post, int $baseVersion, User $actor): Post
    {
        return $this->locked($company, $post, $baseVersion, 'withdraw', [Post::PENDING, Post::SCHEDULED], function (Post $post) use ($company, $actor) {
            $post->forceFill(['status' => Post::DRAFT, 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record(action: 'social_commerce.post_withdrawn', target: $post, actor: $actor, organizationId: $company->getKey());
        });
    }

    /** Removes a post that never went out, with its photos. */
    public function delete(Organization $company, Post $post, int $baseVersion, User $actor): void
    {
        $this->book->transaction($company, function () use ($company, $post, $baseVersion, $actor) {
            $post = $this->book->query(Post::class, $company)->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            if ($post->version !== $baseVersion) {
                throw SocialCommerceException::versionConflict();
            }
            if (! $post->editable() || $post->fb_post_id !== null) {
                throw SocialCommerceException::wrongStatus($this->actionName('delete'), $this->statusName($post->status));
            }
            foreach ($this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->get() as $media) {
                Storage::disk('local')->delete($media->path);
                $media->delete();
            }
            $this->book->query(PostProduct::class, $company)->where('post_id', $post->getKey())->delete();
            $post->delete();
            $this->audit->record(action: 'social_commerce.post_deleted', target: $post, actor: $actor, organizationId: $company->getKey());
        });
    }

    /**
     * Scheduled: posts whose time has come go to the queue; posts stuck in
     * "publishing" (the worker stopped) are marked failed, never sent twice.
     * Returns how many were sent to the queue.
     */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;
        $this->book->everyDatabase(function () use ($now, &$sent) {
            $base = fn () => Post::query()->withoutGlobalScope(OrganizationScope::class);
            $base()->where('status', Post::PUBLISHING)->where('updated_at', '<', $now->subMinutes(self::STUCK_MINUTES))
                ->update(['status' => Post::FAILED, 'error_code' => 'interrupted', 'updated_at' => $now]);
            $due = $base()->where('status', Post::SCHEDULED)->where(fn ($where) => $where->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
                ->orderBy('publish_at')->limit(200)->get(['id', 'organization_id']);
            foreach ($due as $post) {
                $company = Organization::query()->find($post->organization_id);
                if ($company !== null && $this->modules->isEnabled('social_commerce', $company)) {
                    PublishPost::dispatch($company->getKey(), $post->getKey());
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /**
     * The products a post shows (comment replies in SC-2 start here).
     *
     * @return list<array{item_id: string, price_minor: ?int, currency_code: string}>
     */
    public function productsOf(Organization $company, string $postId): array
    {
        return $this->book->query(PostProduct::class, $company)->where('post_id', $postId)->orderBy('created_at')->orderBy('id')
            ->get(['item_id', 'price_minor', 'currency_code'])->map(fn (PostProduct $row) => [
                'item_id' => $row->item_id, 'price_minor' => $row->price_minor, 'currency_code' => $row->currency_code,
            ])->all();
    }

    /**
     * Items a post may show (?search), each with the line a post would carry for it in the language:
     * the name, and the price and stock where the rules show them.
     *
     * @return list<array{id: string, sku: string, name: string, sale_price_minor: ?int, in_stock: ?bool, line: string}>
     */
    public function items(Organization $company, ?string $search, string $locale): array
    {
        $context = $this->contexts->forOrganization($company);
        $showPrice = $this->rules->get('social_commerce.show_price', $context) === true;
        $showStock = $this->rules->get('social_commerce.show_stock', $context) === true;
        $currency = $this->book->currency($company);
        $items = $this->stock->items($company, null, $search, 50);
        $onHand = $showStock ? $this->stock->onHandAll($company, array_column(array_filter($items, fn (array $item) => $item['kind'] === 'stock'), 'id')) : [];

        return array_map(function (array $item) use ($locale, $showPrice, $showStock, $currency, $onHand) {
            $name = (string) (($item['names'][$locale] ?? null) ?: $item['name']);
            $inStock = $item['kind'] === 'stock' ? ($onHand[$item['id']] ?? 0) > 0 : null;
            $parts = [$name];
            if ($showPrice && $item['sale_price_minor'] !== null && $currency !== '') {
                $parts[] = __('social_commerce::social.line.price', ['price' => MoneyText::shown((int) $item['sale_price_minor'], $currency, $locale)], $locale);
            }
            if ($showStock && $inStock !== null) {
                $parts[] = __($inStock ? 'social_commerce::social.line.in_stock' : 'social_commerce::social.line.out_of_stock', [], $locale);
            }

            return [
                'id' => $item['id'], 'sku' => $item['sku'], 'name' => $name, 'sale_price_minor' => $item['sale_price_minor'],
                'in_stock' => $showStock ? $inStock : null, 'line' => implode(' — ', $parts),
            ];
        }, $items);
    }

    /** The footer line of a post in its language (rule social_commerce.post_footer), or ''. */
    public function footer(Organization $company, string $locale): string
    {
        $footer = (array) $this->rules->get('social_commerce.post_footer', $this->contexts->forOrganization($company));

        return trim((string) ($footer[$locale] ?? $footer['en'] ?? ''));
    }

    public function actionName(string $action): string
    {
        return __("social_commerce::social.actions.{$action}");
    }

    public function statusName(string $status): string
    {
        return __("social_commerce::social.status.{$status}");
    }

    // ---------------------------------------------------------------- inside

    /**
     * Runs a change on the post, locked, at the version the person saw and in one of the statuses allowed.
     *
     * @param  list<string>  $statuses
     * @param  callable(Post): void  $change
     */
    private function locked(Organization $company, Post $post, int $baseVersion, string $action, array $statuses, callable $change): Post
    {
        return $this->book->transaction($company, function () use ($company, $post, $baseVersion, $action, $statuses, $change) {
            $post = $this->book->query(Post::class, $company)->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            if ($post->version !== $baseVersion) {
                throw SocialCommerceException::versionConflict();
            }
            if (! in_array($post->status, $statuses, true)) {
                throw SocialCommerceException::wrongStatus($this->actionName($action), $this->statusName($post->status));
            }
            $change($post);
            $post->version++;
            $post->save();

            return $post;
        });
    }

    private function assertReady(Organization $company, Post $post, bool $checkTime = true): void
    {
        $page = $this->book->query(SocialPage::class, $company)->find($post->page_id);
        if ($page === null || ! $page->usable()) {
            throw SocialCommerceException::pageUnusable((string) $page?->name);
        }
        $hasPhotos = $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->exists();
        if (trim((string) $post->message) === '' && ! $hasPhotos) {
            throw SocialCommerceException::emptyPost();
        }
        if ($checkTime && $post->publish_at !== null) {
            $minutes = (int) $this->rules->get('social_commerce.min_schedule_minutes', $this->contexts->forOrganization($company));
            if ($post->publish_at->lt(CarbonImmutable::now()->addMinutes($minutes)->subSeconds(30))) {
                throw SocialCommerceException::tooSoon($minutes);
            }
        }
    }

    private function sendIfDue(Organization $company, Post $post): void
    {
        if ($post->status === Post::SCHEDULED && ($post->publish_at === null || $post->publish_at->lte(CarbonImmutable::now()))) {
            PublishPost::dispatch($company->getKey(), $post->getKey());
        }
    }

    private function pageOf(Organization $company, string $pageId): SocialPage
    {
        $page = $this->book->query(SocialPage::class, $company)->whereKey($pageId)->first();
        if ($page === null || $page->status === SocialPage::DISCONNECTED) {
            throw SocialCommerceException::notFound('page');
        }

        return $page;
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array{item_id: string, price_minor: ?int}>
     */
    private function products(Organization $company, array $itemIds): array
    {
        $itemIds = array_values(array_unique($itemIds));
        if ($itemIds === []) {
            return [];
        }
        $items = $this->stock->items($company, $itemIds);
        if (count($items) !== count($itemIds)) {
            throw SocialCommerceException::unknownItem();
        }

        return array_map(fn (array $item) => ['item_id' => $item['id'], 'price_minor' => $item['sale_price_minor']], $items);
    }

    /**
     * @param  list<array{item_id: string, price_minor: ?int}>  $products
     */
    private function keepProducts(Organization $company, Post $post, array $products): void
    {
        $currency = $this->book->currency($company);
        foreach ($products as $product) {
            $this->book->query(PostProduct::class, $company)->create([
                'organization_id' => $company->getKey(),
                'post_id' => $post->getKey(),
                'item_id' => $product['item_id'],
                'price_minor' => $product['price_minor'],
                'currency_code' => $currency,
            ]);
        }
    }

    private function clean(?string $message): ?string
    {
        $message = $message === null ? null : trim(str_replace("\r\n", "\n", $message));

        return $message === '' ? null : $message;
    }

    private function when(?string $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->utc();
    }
}
