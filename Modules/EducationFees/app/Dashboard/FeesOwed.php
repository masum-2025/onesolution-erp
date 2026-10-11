<?php

namespace Modules\EducationFees\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Services\FeeOffice;

/** What families owe now at the unit and below, and how much of it is overdue. */
final class FeesOwed implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FeePlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0, format: 'money');
        }
        [$company, $units] = $place;
        $office = app(FeeOffice::class);
        $today = $office->today($company)->toDateString();
        $bills = $office->query(Bill::class, $company)->whereIn('unit_id', $units)->where('status', 'open')->get(['due_date', 'total_minor', 'paid_minor']);
        $owed = (int) $bills->sum(fn (Bill $bill) => $bill->balanceMinor());
        $overdue = (int) $bills->filter(fn (Bill $bill) => $bill->due_date->toDateString() < $today)->sum(fn (Bill $bill) => $bill->balanceMinor());
        $currency = rescue(fn () => $office->currency($company), null, false);

        return WidgetData::stat($owed, hint: __('education_fees::dashboard.owed_hint', ['overdue' => $currency === null ? (string) $overdue : MoneyText::format($overdue, $currency, app()->getLocale())]), format: 'money', currency: $currency);
    }
}
