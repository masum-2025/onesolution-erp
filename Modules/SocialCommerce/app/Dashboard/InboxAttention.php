<?php

namespace Modules\SocialCommerce\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Services\Conversations;
use Modules\SocialCommerce\Services\SocialBook;

/** In the bell: Facebook conversations waiting for a person to answer (people who answer the inbox). */
final class InboxAttention implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || ! Gate::allows('social_commerce.inbox', $unit)) {
            return [];
        }
        try {
            $count = app(Conversations::class)->waiting(app(SocialBook::class)->companyOf($unit));
        } catch (SocialCommerceException) {
            return [];
        }

        return $count > 0 ? [new AttentionItem('social_commerce.waiting', __('social_commerce::social.attention'), $count, '/social/inbox?status=waiting', 'warn')] : [];
    }
}
