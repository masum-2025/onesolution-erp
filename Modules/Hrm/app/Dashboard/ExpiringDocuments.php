<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Models\EmployeeDocument;
use Modules\Hrm\Services\DocumentExpiry;

/**
 * Employee documents to renew: expired, or expiring within the reminder
 * window (rule hrm.document_expiry_alert_days). A dashboard list for anyone
 * who reads HRM, and a count in the bell for those who manage employees.
 */
final class ExpiringDocuments implements AttentionProvider, DashboardWidget
{
    use ReadsEmployees;

    private const SHOWN = 6;

    public function __construct(private DocumentExpiry $expiry) {}

    public function data(CurrentContext $context): array
    {
        return WidgetData::list($this->due($context)->take(self::SHOWN)->map(fn (array $pair) => [
            'label' => $pair[0]->employee?->full_name ?? '—',
            'meta' => $pair[0]->title.' · '.($pair[1]['state'] === 'expired'
                ? __('hrm::dashboard.expired')
                : trans_choice('hrm::dashboard.expires_in', $pair[1]['days_left'], ['count' => $pair[1]['days_left']])),
            'date' => $pair[0]->expires_on->toDateString(),
            'path' => "/hrm/employees/{$pair[0]->employee_id}?tab=documents",
            'tone' => $pair[1]['state'] === 'expired' ? 'bad' : 'warn',
        ])->values()->all());
    }

    public function items(CurrentContext $context): array
    {
        if (! Gate::allows('hrm.manage', $context->organization())) {
            return [];
        }

        $due = $this->due($context);
        if ($due->isEmpty()) {
            return [];
        }

        $expired = $due->filter(fn (array $pair) => $pair[1]['state'] === 'expired')->count();

        return [new AttentionItem('hrm.documents_expiring', __('hrm::dashboard.attention'), $due->count(), '/m/hrm', $expired > 0 ? 'bad' : 'warn')];
    }

    /**
     * @return Collection<int, array{0: EmployeeDocument, 1: array{state: string, days_left: int, stage: int}}>
     */
    private function due(CurrentContext $context): Collection
    {
        $days = $this->expiry->daysFor($context->organization());
        if ($days === []) {
            return collect();
        }

        $today = $this->today($context);

        return EmployeeDocument::query()
            ->with('employee')
            ->whereIn('organization_id', $this->unitIds($context))
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->addDays(max($days))->toDateString())
            ->whereIn('employee_id', $this->employees($context)->where('status', '!=', EmployeeStatus::Exited->value)->select('id'))
            ->orderBy('expires_on')
            ->get()
            ->map(fn (EmployeeDocument $document) => [$document, DocumentExpiry::state($document->expires_on, $today, $days)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->values();
    }
}
