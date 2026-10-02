<?php

namespace App\Platform\Attention\Providers;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;

/** Rule changes waiting for a second person (maker-checker), as on the approvals screen. */
final class PendingRuleApprovals implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $organization = $context->organization();
        if (! Gate::allows('rules.approve', $organization)) {
            return [];
        }

        $count = RuleValue::query()
            ->where('status', RuleValueStatus::PendingApproval)
            ->whereIn('scope_type', [RuleScope::Group, RuleScope::Company, RuleScope::Branch, RuleScope::Department])
            ->whereIn('scope_id', Organization::query()->visibleTo($context)->subtreeOf($organization)->select('id'))
            ->count();

        return $count === 0 ? [] : [new AttentionItem('rules.approvals', __('rules.attention.approvals'), $count, '/approvals', 'warn')];
    }
}
