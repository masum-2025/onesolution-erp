<?php

namespace Modules\Compliance\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\AuditChange;
use Modules\Compliance\Models\AuditType;
use Modules\Compliance\Models\Provider;
use Modules\Compliance\Models\ReceivedInvoice;
use Modules\Compliance\Models\ReceivedQuote;
use Modules\Compliance\Models\Service;

/**
 * Compliance in the client's data export: the lists, the register and every
 * change of it (with reasons).
 */
class ComplianceExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'compliance';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $named = fn (Model $item) => ['id' => $item->getKey(), 'kept_by' => $item->organization_id, 'code' => $item->code, 'name' => json_encode($item->texts('name'), JSON_UNESCAPED_UNICODE), 'is_active' => $item->is_active];

        return [
            'services' => $this->rows(Service::class, $organization, $organizationIds, fn (Service $item) => [...$named($item), 'validity_months' => $item->validity_months]),
            'audit_types' => $this->rows(AuditType::class, $organization, $organizationIds, $named),
            'providers' => $this->rows(Provider::class, $organization, $organizationIds, fn (Provider $item) => [
                'id' => $item->getKey(), 'kept_by' => $item->organization_id, 'name' => $item->name, 'email' => $item->email, 'phone' => $item->phone, 'is_active' => $item->is_active,
            ]),
            'register' => $this->rows(Audit::class, $organization, $organizationIds, fn (Audit $audit) => [
                'id' => $audit->getKey(), 'factory_id' => $audit->organization_id, 'service_id' => $audit->service_id, 'provider_id' => $audit->provider_id,
                'audit_type_id' => $audit->audit_type_id, 'last_audit_on' => $audit->last_audit_on?->toDateString(), 'expires_on' => $audit->expires_on?->toDateString(),
                'result' => $audit->result, 'certificate_no' => $audit->certificate_no, 'notes' => $audit->notes,
            ]),
            // Quotations connected providers sent, and the answers given.
            'provider_quotes' => $this->rows(ReceivedQuote::class, $organization, $organizationIds, fn (ReceivedQuote $quote) => [
                'id' => $quote->getKey(), 'factory_id' => $quote->organization_id, 'from' => $quote->from_name, 'number' => $quote->number,
                'currency_code' => $quote->currency_code, 'total_minor' => $quote->total_minor, 'lines' => json_encode($quote->lines, JSON_UNESCAPED_UNICODE),
                'status' => $quote->status, 'reason' => $quote->reason, 'at' => $quote->created_at?->toIso8601String(),
            ]),
            // Their invoices, as they last came, and the bill made of each.
            'provider_invoices' => $this->rows(ReceivedInvoice::class, $organization, $organizationIds, fn (ReceivedInvoice $invoice) => [
                'id' => $invoice->getKey(), 'factory_id' => $invoice->organization_id, 'from' => $invoice->from_name, 'number' => $invoice->number,
                'issue_date' => $invoice->issue_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'currency_code' => $invoice->currency_code, 'total_minor' => $invoice->total_minor, 'balance_minor' => $invoice->balance_minor,
                'lines' => json_encode($invoice->lines, JSON_UNESCAPED_UNICODE), 'status' => $invoice->provider_status, 'bill_id' => $invoice->bill_id,
            ]),
            'changes' => $this->rows(AuditChange::class, $organization, $organizationIds, fn (AuditChange $change) => [
                'audit_id' => $change->audit_id, 'kind' => $change->kind, 'old' => json_encode($change->old), 'new' => json_encode($change->new),
                'reason' => $change->reason, 'user_id' => $change->user_id, 'at' => $change->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
