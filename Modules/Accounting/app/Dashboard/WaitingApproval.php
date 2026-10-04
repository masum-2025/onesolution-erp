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
use Modules\Accounting\Models\Settlement;
use Modules\Accounting\Services\Books;

/**
 * Journal entries, documents and money records waiting for approval: a
 * count on the dashboard, and in the bell for approvers (only what they did
 * not write or send themselves).
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
        if ($company === null || ! Gate::allows('accounting.approve', $company)) {
            return [];
        }

        $mine = $context->user()?->getKey();
        $notMine = fn (Builder $query, string $column) => $query->where(fn ($inner) => $inner->whereNull($column)->orWhere($column, '!=', $mine));
        $count = 0;
        foreach ($this->waiting($company) as $kind => $query) {
            $query = $notMine($query, 'created_by');
            if ($kind !== 'settlements') {
                $query = $notMine($query, 'submitted_by');
            }
            $count += $query->count();
        }

        return $count === 0 ? [] : [new AttentionItem('accounting.journals_waiting', __('accounting::dashboard.attention'), $count, '/accounting/approvals', 'warn')];
    }

    /**
     * @return array{journals: Builder<Journal>, documents: Builder<Document>, settlements: Builder<Settlement>}
     */
    private function waiting(Organization $company): array
    {
        $books = app(Books::class);

        return [
            'journals' => $books->query(Journal::class, $company)->where('status', JournalStatus::PendingApproval->value),
            'documents' => $books->query(Document::class, $company)->where('status', DocumentStatus::PendingApproval->value),
            'settlements' => $books->query(Settlement::class, $company)->where('status', SettlementStatus::PendingApproval->value),
        ];
    }
}
