<?php

namespace Modules\Crm\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Field;
use Modules\Crm\Models\Quote;
use Modules\Crm\Models\QuoteLine;

/** CRM in the client's data export: contacts, deals, activities, estimates and quotations with lines, and the extra fields. */
class CrmExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'crm';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $json = fn ($value) => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            'contacts' => $this->rows(Contact::class, $organization, $organizationIds, fn (Contact $row) => [
                'id' => $row->getKey(), 'unit_id' => $row->unit_id, 'kind' => $row->kind, 'name' => $row->name, 'company_name' => $row->company_name, 'phone' => $row->phone,
                'email' => $row->email, 'address' => $json($row->address), 'tags' => $json($row->tags), 'source' => $row->source, 'sms_consent' => $row->sms_consent,
                'email_consent' => $row->email_consent, 'consent_at' => $row->consent_at?->toIso8601String(), 'extra' => $json($row->extra), 'spent_minor' => $row->spent_minor,
                'purchases' => $row->purchases, 'points' => $row->points, 'anonymized_at' => $row->anonymized_at?->toIso8601String(),
            ]),
            'deals' => $this->rows(Deal::class, $organization, $organizationIds, fn (Deal $row) => [
                'id' => $row->getKey(), 'contact_id' => $row->contact_id, 'pipeline_id' => $row->pipeline_id, 'stage_id' => $row->stage_id, 'title' => $row->title,
                'value_minor' => $row->value_minor, 'currency_code' => $row->currency_code, 'status' => $row->status, 'expected_on' => $row->expected_on?->toDateString(), 'extra' => $json($row->extra),
            ]),
            'activities' => $this->rows(Activity::class, $organization, $organizationIds, fn (Activity $row) => [
                'id' => $row->getKey(), 'contact_id' => $row->contact_id, 'deal_id' => $row->deal_id, 'kind' => $row->kind, 'subject' => $row->subject, 'body' => $row->body,
                'due_at' => $row->due_at?->toIso8601String(), 'done_at' => $row->done_at?->toIso8601String(), 'assigned_to' => $row->assigned_to,
            ]),
            'quotes' => $this->rows(Quote::class, $organization, $organizationIds, fn (Quote $row) => [
                'id' => $row->getKey(), 'kind' => $row->kind, 'number' => $row->number, 'status' => $row->status, 'contact_id' => $row->contact_id, 'deal_id' => $row->deal_id,
                'issue_date' => $row->issue_date->toDateString(), 'valid_until' => $row->valid_until?->toDateString(), 'total_minor' => $row->total_minor, 'tax_minor' => $row->tax_minor,
                'currency_code' => $row->currency_code, 'extra' => $json($row->extra), 'invoice_id' => $row->invoice_id,
            ]),
            'quote_lines' => $this->rows(QuoteLine::class, $organization, $organizationIds, fn (QuoteLine $row) => [
                'quote_id' => $row->quote_id, 'line_no' => $row->line_no, 'item_id' => $row->item_id, 'description' => $row->description, 'quantity_milli' => $row->quantity_milli,
                'unit_price_minor' => $row->unit_price_minor, 'discount_minor' => $row->discount_minor, 'tax_minor' => $row->tax_minor, 'total_minor' => $row->total_minor, 'extra' => $json($row->extra),
            ]),
            'fields' => $this->rows(Field::class, $organization, $organizationIds, fn (Field $row) => [
                'entity' => $row->entity, 'key' => $row->key, 'label' => $json($row->texts('label')), 'type' => $row->type, 'options' => $json($row->options), 'is_active' => $row->is_active,
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
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
