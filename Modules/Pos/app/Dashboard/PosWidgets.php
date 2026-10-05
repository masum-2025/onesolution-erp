<?php

namespace Modules\Pos\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\Session;
use Modules\Pos\Services\Tills;

/**
 * Point of sale on the dashboard and in the bell, for the counters at the
 * unit in the context and below: today's takings (sales less returns);
 * shifts with a cash difference and offline sales waiting for a supervisor.
 */
final class PosWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        [$company, $registers] = $this->scope($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $sales = app(Tills::class)->query(Sale::class, $company)->whereIn('register_id', $registers)->where('sold_at', '>=', CarbonImmutable::now()->startOfDay())->get(['kind', 'total_minor']);
        $total = (int) $sales->where('kind', 'sale')->sum('total_minor') - (int) $sales->where('kind', 'return')->sum('total_minor');

        return WidgetData::stat($total, hint: __('pos::dashboard.today_hint', ['count' => $sales->where('kind', 'sale')->count()]), format: 'money', currency: app(Tills::class)->currency($company));
    }

    public function items(CurrentContext $context): array
    {
        [$company, $registers] = $this->scope($context);
        if ($company === null || ! Gate::allows('pos.supervise', $context->organization())) {
            return [];
        }
        $me = $context->user()?->getKey();
        $tills = app(Tills::class);
        $shifts = $tills->query(Session::class, $company)->whereIn('register_id', $registers)->where('status', Session::PENDING)
            ->where('opened_by', '!=', $me)->where(fn ($query) => $query->whereNull('closed_by')->orWhere('closed_by', '!=', $me))->count();
        $sales = $tills->query(Sale::class, $company)->whereIn('register_id', $registers)->whereNotNull('review_reason')->where('sold_at', '>=', CarbonImmutable::now()->subDays(7))->count();

        return array_values(array_filter([
            $shifts === 0 ? null : new AttentionItem('pos.shifts_review', __('pos::dashboard.attention_shifts'), $shifts, '/pos/shifts?status=pending_review', 'warn'),
            $sales === 0 ? null : new AttentionItem('pos.sales_review', __('pos::dashboard.attention_sales'), $sales, '/pos/sales?review=1', 'warn'),
        ]));
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
        $tills = app(Tills::class);
        $company = $tills->companyOf($unit);

        return [$company, $tills->query(Register::class, $company)->whereIn('unit_id', $tills->subtreeIds($unit))->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }
}
