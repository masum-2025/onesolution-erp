<?php

namespace App\Platform\Attention\Providers;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;

/** The provider asked to look inside: someone here has to say yes or no. */
final class PendingSupportRequests implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $organization = $context->organization();
        if (! Gate::allows('support.approve', $organization)) {
            return [];
        }

        $count = SupportGrant::query()
            ->where('status', GrantStatus::Pending)
            ->whereIn('organization_id', Organization::query()->subtreeOf($organization)->select('id'))
            ->count();

        return $count === 0 ? [] : [new AttentionItem('support.requests', __('support.attention.requests'), $count, '/support-access', 'warn')];
    }
}
