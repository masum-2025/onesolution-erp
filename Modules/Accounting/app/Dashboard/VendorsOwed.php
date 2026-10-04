<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;

/** What the company owes vendors now. */
final class VendorsOwed extends OwedAmount implements DashboardWidget
{
    protected function side(): string
    {
        return 'purchases';
    }

    protected function overdueOnly(): bool
    {
        return false;
    }
}
