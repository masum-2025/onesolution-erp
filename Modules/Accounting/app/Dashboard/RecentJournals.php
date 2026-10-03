<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\Books;

/** The latest posted journal entries, each opening its page. */
final class RecentJournals implements DashboardWidget
{
    use ReadsBooks;

    private const SHOWN = 6;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::list([]);
        }

        return WidgetData::list(app(Books::class)->query(Journal::class, $company)
            ->where('status', JournalStatus::Posted->value)
            ->orderByDesc('posted_at')->orderByDesc('id')
            ->limit(self::SHOWN)->get()
            ->map(fn (Journal $journal) => [
                'label' => $journal->narration,
                'meta' => $journal->number,
                'date' => $journal->entry_date->toDateString(),
                'path' => "/accounting/journals/{$journal->getKey()}",
            ])->all());
    }
}
