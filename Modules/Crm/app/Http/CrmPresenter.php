<?php

namespace Modules\Crm\Http;

use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Field;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Quote;
use Modules\Crm\Models\QuoteLine;
use Modules\Crm\Models\Stage;

/** CRM records as the API shows them: money in minor units with its currency, days as YYYY-MM-DD, times ISO 8601. */
class CrmPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function contact(Contact $contact): array
    {
        return [
            'id' => $contact->getKey(), 'unit_id' => $contact->unit_id, 'kind' => $contact->kind, 'name' => $contact->name, 'company_name' => $contact->company_name,
            'phone' => $contact->phone, 'email' => $contact->email, 'address' => $contact->address, 'tags' => $contact->tags ?? [], 'source' => $contact->source,
            'owner_id' => $contact->owner_id, 'sms_consent' => $contact->sms_consent, 'email_consent' => $contact->email_consent, 'consent_at' => $contact->consent_at?->toIso8601String(),
            'extra' => (object) ($contact->extra ?? []), 'spent_minor' => (int) $contact->spent_minor, 'purchases' => (int) $contact->purchases,
            'last_purchase_on' => $contact->last_purchase_on?->toDateString(), 'points' => (int) $contact->points, 'is_active' => $contact->is_active,
            'anonymized' => $contact->anonymized_at !== null, 'created_at' => $contact->created_at?->toIso8601String(), 'version' => $contact->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deal(Deal $deal): array
    {
        return [
            'id' => $deal->getKey(), 'unit_id' => $deal->unit_id, 'contact_id' => $deal->contact_id, 'pipeline_id' => $deal->pipeline_id, 'stage_id' => $deal->stage_id,
            'title' => $deal->title, 'value_minor' => $deal->value_minor, 'currency' => $deal->currency_code, 'expected_on' => $deal->expected_on?->toDateString(),
            'owner_id' => $deal->owner_id, 'status' => $deal->status, 'lost_reason' => $deal->lost_reason, 'closed_at' => $deal->closed_at?->toIso8601String(),
            'extra' => (object) ($deal->extra ?? []), 'created_at' => $deal->created_at?->toIso8601String(), 'version' => $deal->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(Activity $activity): array
    {
        return [
            'id' => $activity->getKey(), 'contact_id' => $activity->contact_id, 'deal_id' => $activity->deal_id, 'kind' => $activity->kind, 'subject' => $activity->subject,
            'body' => $activity->body, 'due_at' => $activity->due_at?->toIso8601String(), 'done_at' => $activity->done_at?->toIso8601String(),
            'assigned_to' => $activity->assigned_to, 'created_by' => $activity->created_by, 'created_at' => $activity->created_at?->toIso8601String(), 'version' => $activity->version,
        ];
    }

    /**
     * @param  iterable<QuoteLine>|null  $lines
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function quote(Quote $quote, bool $expired = false, ?iterable $lines = null, array $can = []): array
    {
        return [
            'id' => $quote->getKey(), 'unit_id' => $quote->unit_id, 'kind' => $quote->kind, 'number' => $quote->number, 'status' => $quote->status, 'expired' => $expired,
            'contact_id' => $quote->contact_id, 'deal_id' => $quote->deal_id, 'from_quote_id' => $quote->from_quote_id, 'issue_date' => $quote->issue_date->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(), 'subject' => $quote->subject, 'notes' => $quote->notes, 'terms' => $quote->terms,
            'currency' => $quote->currency_code, 'prices_include_tax' => $quote->prices_include_tax, 'subtotal_minor' => $quote->subtotal_minor,
            'discount_minor' => $quote->discount_minor, 'tax_minor' => $quote->tax_minor, 'total_minor' => $quote->total_minor, 'extra' => (object) ($quote->extra ?? []),
            'invoice_id' => $quote->invoice_id, 'decline_reason' => $quote->decline_reason, 'sent_at' => $quote->sent_at?->toIso8601String(),
            'decided_at' => $quote->decided_at?->toIso8601String(), 'version' => $quote->version,
            ...($lines === null ? [] : ['lines' => collect($lines)->map(fn (QuoteLine $line) => [
                ...$line->only(['id', 'line_no', 'item_id', 'description', 'quantity_milli', 'unit', 'unit_price_minor', 'discount_minor', 'tax_code_id', 'tax_rate_bp', 'net_minor', 'tax_minor', 'total_minor']),
                'extra' => (object) ($line->extra ?? []),
            ])->values()->all()]),
            ...($can === [] ? [] : ['can' => $can]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pipeline(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->getKey(), 'key' => $pipeline->key, 'name' => $pipeline->textIn('name'), 'names' => $pipeline->texts('name'),
            'is_default' => $pipeline->is_default, 'is_active' => $pipeline->is_active, 'version' => $pipeline->version,
            'stages' => $pipeline->relationLoaded('stages') ? $pipeline->stages->map(fn (Stage $stage) => $this->stage($stage))->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(Stage $stage): array
    {
        return [
            'id' => $stage->getKey(), 'pipeline_id' => $stage->pipeline_id, 'key' => $stage->key, 'name' => $stage->textIn('name'), 'names' => $stage->texts('name'),
            'sort_order' => $stage->sort_order, 'probability_bp' => $stage->probability_bp, 'outcome' => $stage->outcome, 'is_active' => $stage->is_active, 'version' => $stage->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function field(Field $field): array
    {
        return [
            'id' => $field->getKey(), 'entity' => $field->entity, 'key' => $field->key, 'label' => $field->textIn('label'), 'labels' => $field->texts('label'),
            'type' => $field->type, 'options' => array_map(fn (array $option) => ['value' => $option['value'], 'label' => $option['label'][app()->getLocale()] ?? $option['label']['en'] ?? $option['value'], 'labels' => $option['label'] ?? []], $field->options ?? []),
            'is_required' => $field->is_required, 'on_print' => $field->on_print, 'sort_order' => $field->sort_order, 'is_active' => $field->is_active, 'version' => $field->version,
        ];
    }
}
