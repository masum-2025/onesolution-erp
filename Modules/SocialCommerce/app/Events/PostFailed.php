<?php

namespace Modules\SocialCommerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** social_commerce.post_failed: Facebook did not take a post (its error code says why). */
class PostFailed
{
    use Dispatchable;

    public function __construct(public readonly string $companyId, public readonly string $postId, public readonly string $errorCode) {}
}
