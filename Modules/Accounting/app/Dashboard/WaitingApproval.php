<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Opening;
use Modules\Accounting\Models\Settlement;
use Modules\Accounting\Models\YearReopenRequest;
use Modules\Accounting\Services\Books;

/**
 * Journal entries, documents, money records and opening balances waiting
 * for approval: a count on the dashboard, and in the bell for approvers
 * (only what they did not write or send themselves). Requests to reopen a
 * closed year ring for the other people who close the books.
 */
final class WaitingApproval implements AttentionProvider, DashboardWidget
{
    use ReadsBooks;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        $count = $company === null ? 0 : array_sum(array_map(fn (Builder $query) => $query->count(), $this->waiting($company)));

        return WidgetData::stat($count, hint: __('accounting::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return [];
        }

        $mine = $context->user()?->getKey();
        $notMine = fn (Builder $query, string $column) => $query->where(fn ($inner) => $inner->whereNull($column)->orWhere($column, '!=', $mine));
        $items = [];
        if (Gate::allows('accounting.approve', $company)) {
            $counts = [];
            foreach ($this->waiting($company) as $kind => $query) {
                $query = $notMine($query, 'created_by');
                if ($kind !== 'settlements') {
                    $query = $notMine($query, 'submitted_by');
                }
                $counts[$kind] = $query->count();
            }
            $count = $counts['journals'] + $counts['documents'] + $counts['settlements'];
            if ($count > 0) {
                $items[] = new AttentionItem('accounting.journals_waiting', __('accounting::dashboard.attention'), $count, '/accounting/approvals', 'warn');
            }
            if ($counts['openings'] > 0) {
                $items[] = new AttentionItem('accounting.opening_waiting', __('accounting::dashboard.attention_opening'), 1, '/accounting/opening', 'warn');
            }
        }
        if (Gate::allows('accounting.close', $company)) {
            $reopen = $notMine(app(Books::class)->query(YearReopenRequest::class, $company)->where('status', YearReopenRequest::PENDING), 'requested_by')->count();
            if ($reopen > 0) {
                $items[] = new AttentionItem('accounting.reopen_waiting', __('accounting::dashboard.attention_reopen'), $reopen, '/accounting/fiscal-years', 'warn');
            }
        }

        return $items;
    }

    /**
     * @return array{journals: Builder<Journal>, documents: Builder<Document>, settlements: Builder<Settlement>, openings: Builder<Opening>}
     */
    private function waiting(Organization $company): array
    {
        $books = app(Books::class);

        return [
            'journals' => $books->query(Journal::class, $company)->where('status', JournalStatus::PendingApproval->value),
            'documents' => $books->query(Document::class, $company)->where('status', DocumentStatus::PendingApproval->value),
            'settlements' => $books->query(Settlement::class, $company)->where('status', SettlementStatus::PendingApproval->value),
            'openings' => $books->query(Opening::class, $company)->where('status', JournalStatus::PendingApproval->value),
        ];
    }
}
