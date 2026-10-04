<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Accounting\Models\BankLine;
use Modules\Accounting\Services\Books;

/** Statement lines not matched to the books yet (all accounts). */
final class BankUnmatched implements DashboardWidget
{
    use ReadsBooks;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        $count = $company === null ? 0 : app(Books::class)->query(BankLine::class, $company)
            ->whereNull('reconciliation_id')->where('matched_minor', 0)->count();

        return WidgetData::stat($count, hint: __('accounting::dashboard.bank_unmatched_hint'));
    }
}
