<?php

namespace Modules\Expenses\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Expenses\Models\Approval;
use Modules\Expenses\Models\Category;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Models\Line;
use Modules\Expenses\Models\Receipt;

/**
 * Expenses in the client's data export: kinds, claims with their lines and
 * approval steps, and the list of receipts (names and kinds; the files stay
 * on the server). Money in minor units with the currency.
 */
class ExpensesExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'expenses';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'categories' => $this->rows(Category::class, $organization, $organizationIds, fn (Category $category) => [
                'id' => $category->getKey(), 'code' => $category->code, 'name' => json_encode($category->texts('name'), JSON_UNESCAPED_UNICODE),
                'posting_key' => $category->posting_key, 'mileage' => $category->mileage, 'is_active' => $category->is_active,
            ]),
            'claims' => $this->rows(Claim::class, $organization, $organizationIds, fn (Claim $claim) => [
                'id' => $claim->getKey(), 'number' => $claim->number, 'unit_id' => $claim->unit_id, 'employee_id' => $claim->employee_id, 'title' => $claim->title,
                'currency' => $claim->currency, 'total_minor' => $claim->total_minor, 'status' => $claim->status, 'submitted_at' => $claim->submitted_at?->toIso8601String(),
                'decision_note' => $claim->decision_note, 'pay_method' => $claim->pay_method, 'pay_period' => $claim->pay_period, 'paid_at' => $claim->paid_at?->toIso8601String(),
            ]),
            'lines' => $this->rows(Line::class, $organization, $organizationIds, fn (Line $line) => [
                'claim_id' => $line->claim_id, 'line_no' => $line->line_no, 'spent_on' => $line->spent_on->toDateString(), 'category_id' => $line->category_id,
                'description' => $line->description, 'amount_minor' => $line->amount_minor, 'distance_tenths' => $line->distance_tenths,
            ]),
            'approvals' => $this->rows(Approval::class, $organization, $organizationIds, fn (Approval $approval) => [
                'claim_id' => $approval->claim_id, 'step' => $approval->step, 'user_id' => $approval->user_id, 'decision' => $approval->decision,
                'note' => $approval->note, 'at' => $approval->created_at?->toIso8601String(),
            ]),
            'receipts' => $this->rows(Receipt::class, $organization, $organizationIds, fn (Receipt $receipt) => [
                'claim_id' => $receipt->claim_id, 'line_id' => $receipt->line_id, 'name' => $receipt->name, 'mime' => $receipt->mime, 'size_bytes' => $receipt->size_bytes,
            ]),
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
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
