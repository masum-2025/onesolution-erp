<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use Modules\Accounting\Enums\AccountType;

/** Income posted this month (more is good news). */
final class IncomeThisMonth extends MonthAmount implements DashboardWidget
{
    protected function type(): AccountType
    {
        return AccountType::Income;
    }

    protected function upIsGood(): bool
    {
        return true;
    }
}
