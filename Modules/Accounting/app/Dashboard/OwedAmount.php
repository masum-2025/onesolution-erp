<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Receivables;

/**
 * What customers owe the company, or what it owes vendors, today: open
 * invoices (bills) less unused credits and advances; or only the part that
 * is already overdue. Money in minor units; the app writes it in the
 * reader's language.
 */
abstract class OwedAmount
{
    use ReadsBooks;

    /** @return 'sales'|'purchases' */
    abstract protected function side(): string;

    abstract protected function overdueOnly(): bool;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0, hint: __('accounting::dashboard.not_set_up'));
        }

        $aging = app(Receivables::class)->aging($company, $this->side(), app(Books::class)->today($company)->toDateString());
        $buckets = $aging['totals']['buckets'];

        return WidgetData::stat(
            value: $this->overdueOnly() ? array_sum($buckets) - $buckets['current'] : $aging['totals']['net_minor'],
            hint: __('accounting::dashboard.'.$this->side().($this->overdueOnly() ? '_overdue_hint' : '_hint')),
            format: 'money',
            currency: $aging['currency'],
        );
    }
}
