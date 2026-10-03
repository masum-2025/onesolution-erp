<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Services\Books;

/**
 * This month's income or expenses so far, the change from the same days of
 * last month, and the last six months for the trend line.
 */
abstract class MonthAmount
{
    use ReadsBooks;

    /** Months on the trend line, this one included. */
    private const MONTHS = 6;

    abstract protected function type(): AccountType;

    abstract protected function upIsGood(): bool;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0, hint: __('accounting::dashboard.not_set_up'));
        }

        $today = $this->today($company);
        $start = $today->startOfMonth();
        $now = $this->amountOf($company, $this->type(), $start, $today);
        $lastStart = $start->subMonthNoOverflow();
        $sameDayLast = $lastStart->addDays(min($today->day, $lastStart->daysInMonth) - 1);

        $series = [];
        for ($back = self::MONTHS - 1; $back >= 1; $back--) {
            $month = $start->subMonthsNoOverflow($back);
            $series[] = $this->amountOf($company, $this->type(), $month, $month->endOfMonth()->startOfDay());
        }
        $series[] = $now;

        return WidgetData::stat(
            value: $now,
            hint: __('accounting::dashboard.month_hint'),
            change: $now - $this->amountOf($company, $this->type(), $lastStart, $sameDayLast),
            upIsGood: $this->upIsGood(),
            series: $series,
            format: 'money',
            currency: app(Books::class)->currency($company),
        );
    }
}
