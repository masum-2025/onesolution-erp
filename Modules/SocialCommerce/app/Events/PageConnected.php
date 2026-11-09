<?php

namespace Modules\SocialCommerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** social_commerce.page_connected: a company connected (or signed in again to) a Facebook page. */
class PageConnected
{
    use Dispatchable;

    public function __construct(public readonly string $companyId, public readonly string $pageId) {}
}
