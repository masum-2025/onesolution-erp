<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Services\LettersOfCredit;

/**
 * In the bell: open letters of credit at the unit that expire within the
 * rule's days (factory_erp.lc_expiry_warn_days), for people who handle them.
 */
final class LcWidgets implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null || ! Gate::allows('factory_erp.merchandise', $context->organization())) {
            return [];
        }
        $days = (int) app(RuleResolver::class)->get('factory_erp.lc_expiry_warn_days', app(RuleContextFactory::class)->forOrganization($context->organization()));
        $count = app(LettersOfCredit::class)->expiringCount($place[0], $place[1], $days);

        return $count === 0 ? [] : [new AttentionItem('factory_erp.lc_expiring', __('factory_erp::dashboard.attention_lc_expiring'), $count, '/factory/lcs', 'warn')];
    }
}
