<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;

/** What customers owe now, less unused credits and advances. */
final class CustomersOwe extends OwedAmount implements DashboardWidget
{
    protected function side(): string
    {
        return 'sales';
    }

    protected function overdueOnly(): bool
    {
        return false;
    }
}
