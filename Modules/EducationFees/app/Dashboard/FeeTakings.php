<?php

namespace Modules\EducationFees\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Services\FeeOffice;

/** Money taken today at the unit and below, with the last seven days for the trend. */
final class FeeTakings implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FeePlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0, format: 'money');
        }
        [$company, $units] = $place;
        $office = app(FeeOffice::class);
        $today = $office->today($company);
        $days = $office->query(Receipt::class, $company)->whereIn('unit_id', $units)->where('status', 'valid')
            ->whereBetween('received_on', [$today->subDays(6)->toDateString(), $today->toDateString()])->get(['received_on', 'amount_minor'])
            ->groupBy(fn (Receipt $receipt) => $receipt->received_on->toDateString())->map(fn ($rows) => (int) $rows->sum('amount_minor'));
        $series = [];
        for ($back = 6; $back >= 0; $back--) {
            $series[] = $days[$today->subDays($back)->toDateString()] ?? 0;
        }

        return WidgetData::stat($series[6], hint: __('education_fees::dashboard.takings_hint'),
            series: $series, format: 'money', currency: rescue(fn () => $office->currency($company), null, false));
    }
}
