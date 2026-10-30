<?php

namespace Modules\Compliance\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Services\Renewals;
use Modules\Compliance\Services\Results;
use Modules\Compliance\Support\Validity;

/**
 * In the bell, at the signed-in unit and below: audits expired or expiring
 * (people who manage compliance), lines whose renewal request is due and
 * not sent (people who send requests), and providers' results waiting to
 * be accepted (people who accept them).
 */
final class ComplianceAttention implements AttentionProvider
{
    use ReadsRegister;

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null) {
            return [];
        }
        $manages = Gate::allows('compliance.manage', $unit);
        $requests = Gate::allows('compliance.request', $unit);
        $confirms = Gate::allows('compliance.confirm', $unit);
        if (! $manages && ! $requests && ! $confirms) {
            return [];
        }
        $items = [];
        $standing = $manages || $requests ? $this->standing($context) : collect();

        if ($manages) {
            $states = $standing->countBy('state');
            $count = (int) ($states[Validity::EXPIRED] ?? 0) + (int) ($states[Validity::EXPIRING] ?? 0);
            if ($count > 0) {
                $items[] = new AttentionItem('compliance.expiring', __('compliance::dashboard.attention'), $count, '/compliance?state='.(($states[Validity::EXPIRED] ?? 0) > 0 ? 'expired' : 'expiring'),
                    ($states[Validity::EXPIRED] ?? 0) > 0 ? 'bad' : 'warn');
            }
        }
        if ($requests) {
            $days = (int) app(RuleResolver::class)->get('compliance.request_days_before', app(RuleContextFactory::class)->forOrganization($unit));
            $due = $standing->filter(fn (array $line) => $line['audit']->provider_id !== null && $line['days_left'] !== null && $line['days_left'] <= $days);
            $latest = app(Renewals::class)->latestOf($unit, $due->map(fn (array $line) => $line['audit']->getKey())->values()->all());
            $count = $due->filter(fn (array $line) => ! in_array($latest->get($line['audit']->getKey())?->status, RenewalRequest::OPEN, true))->count();
            if ($count > 0) {
                $items[] = new AttentionItem('compliance.renewals_due', __('compliance::dashboard.renewals_due'), $count, '/compliance/renewals', 'warn');
            }
        }
        if ($confirms) {
            $count = app(Results::class)->list($unit, pendingOnly: true)->count();
            if ($count > 0) {
                $items[] = new AttentionItem('compliance.results', __('compliance::dashboard.results_waiting'), $count, '/compliance/renewals', 'warn');
            }
        }

        return $items;
    }
}
