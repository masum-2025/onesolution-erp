<?php

namespace Modules\Crm\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Deal;
use Modules\Crm\Services\Crm;

/**
 * CRM on the dashboard and in the bell, for the unit in the context and
 * below: the value of open deals; my follow-ups overdue or due today.
 */
final class CrmWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        [$company, $units] = $this->scope($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $open = app(Crm::class)->query(Deal::class, $company)->whereIn('unit_id', $units)->where('status', Deal::OPEN)->get(['value_minor']);

        return WidgetData::stat((int) $open->sum('value_minor'), hint: __('crm::dashboard.deals_hint', ['count' => $open->count()]), format: 'money', currency: app(Crm::class)->currency($company));
    }

    public function items(CurrentContext $context): array
    {
        [$company, $units] = $this->scope($context);
        $me = $context->user()?->getKey();
        if ($company === null || $me === null) {
            return [];
        }
        $crm = app(Crm::class);
        $end = CarbonImmutable::now($crm->timezone($company))->endOfDay()->utc();
        $due = $crm->query(Activity::class, $company)->whereIn('unit_id', $units)->where('assigned_to', $me)->whereNull('done_at')->where('due_at', '<=', $end)->count();

        return $due === 0 ? [] : [new AttentionItem('crm.follow_ups', __('crm::dashboard.attention_follow_ups'), $due, '/crm/tasks', 'warn')];
    }

    /**
     * @return array{0: Organization|null, 1: list<string>}
     */
    private function scope(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return [null, []];
        }
        $crm = app(Crm::class);

        return [$crm->companyOf($unit), $crm->subtreeIds($unit)];
    }
}
