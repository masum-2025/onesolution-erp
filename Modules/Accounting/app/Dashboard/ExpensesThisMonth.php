<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use Modules\Accounting\Enums\AccountType;

/** Expenses posted this month (more is not good news). */
final class ExpensesThisMonth extends MonthAmount implements DashboardWidget
{
    protected function type(): AccountType
    {
        return AccountType::Expense;
    }

    protected function upIsGood(): bool
    {
        return false;
    }
}
