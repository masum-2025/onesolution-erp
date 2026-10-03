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
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\Books;

/**
 * Journal entries waiting for approval: a count on the dashboard, and in the
 * bell for approvers (only entries they did not write or send themselves).
 */
final class WaitingApproval implements AttentionProvider, DashboardWidget
{
    use ReadsBooks;

    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);

        return WidgetData::stat($company === null ? 0 : $this->waiting($company)->count(), hint: __('accounting::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null || ! Gate::allows('accounting.approve', $company)) {
            return [];
        }

        $mine = $context->user()?->getKey();
        $count = $this->waiting($company)
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $mine))
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $mine))
            ->count();

        return $count === 0 ? [] : [new AttentionItem('accounting.journals_waiting', __('accounting::dashboard.attention'), $count, '/accounting/approvals', 'warn')];
    }

    private function waiting(Organization $company): Builder
    {
        return app(Books::class)->query(Journal::class, $company)->where('status', JournalStatus::PendingApproval->value);
    }
}
