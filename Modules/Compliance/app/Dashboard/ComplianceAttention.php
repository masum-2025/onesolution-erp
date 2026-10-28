<?php

namespace Modules\Compliance\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Support\Validity;

/** In the bell of the people who manage compliance: audits expired or expiring at their unit and below. */
final class ComplianceAttention implements AttentionProvider
{
    use ReadsRegister;

    public function items(CurrentContext $context): array
    {
        if ($context->user() === null || ! Gate::allows('compliance.manage', $context->organization())) {
            return [];
        }
        $states = $this->standing($context)->countBy('state');
        $count = (int) ($states[Validity::EXPIRED] ?? 0) + (int) ($states[Validity::EXPIRING] ?? 0);

        return $count === 0 ? [] : [new AttentionItem('compliance.expiring', __('compliance::dashboard.attention'), $count, '/compliance?state='.(($states[Validity::EXPIRED] ?? 0) > 0 ? 'expired' : 'expiring'),
            ($states[Validity::EXPIRED] ?? 0) > 0 ? 'bad' : 'warn')];
    }
}
