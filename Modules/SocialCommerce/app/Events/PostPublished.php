<?php

namespace Modules\SocialCommerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * social_commerce.post_published: a post is on Facebook. Carries ids only:
 * the products it shows are in social_post_products (read them through
 * Posts::productsOf()).
 */
class PostPublished
{
    use Dispatchable;

    /**
     * @param  list<string>  $itemIds
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $postId,
        public readonly string $fbPostId,
        public readonly array $itemIds,
    ) {}
}
