<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;

/** Money customers owe past its due date. */
final class CustomersOverdue extends OwedAmount implements DashboardWidget
{
    protected function side(): string
    {
        return 'sales';
    }

    protected function overdueOnly(): bool
    {
        return true;
    }
}
