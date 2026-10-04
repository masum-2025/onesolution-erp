<?php

namespace Modules\Accounting\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\TaxCode;

/**
 * VAT for a period: per tax code, the taxable amount and tax of sales
 * (output) and of purchases (input), from posted documents dated in the
 * period (voided ones left out; credit notes and vendor credits reduce),
 * and what is payable (output less input). Lines without a code show as
 * "no tax". Sums are made here, not in SQL.
 */
class TaxReport
{
    public function __construct(private Books $books) {}

    /**
     * @return array<string, mixed>
     */
    public function vat(Organization $company, string $from, string $to): array
    {
        $documents = $this->books->query(Document::class, $company)
            ->whereIn('status', [DocumentStatus::Posted->value, DocumentStatus::PartlyPaid->value, DocumentStatus::Paid->value])
            ->whereBetween('issue_date', [$from, $to])
            ->get()->keyBy('id');

        $rows = [];
        $row = function (?string $codeId) use (&$rows) {
            return $rows[$codeId ?? ''] ??= ['tax_code_id' => $codeId, 'sales_taxable_minor' => 0, 'output_tax_minor' => 0, 'purchases_taxable_minor' => 0, 'input_tax_minor' => 0];
        };

        foreach ($documents->isEmpty() ? [] : $this->books->query(DocumentLine::class, $company)->whereIn('document_id', $documents->keys()->all())->cursor() as $line) {
            $document = $documents[$line->document_id];
            $sign = $document->type->isCredit() ? -1 : 1;
            $row($line->tax_code_id);
            $key = $line->tax_code_id ?? '';
            if ($document->type->isSales()) {
                $rows[$key]['sales_taxable_minor'] += $sign * $line->amount_minor;
                $rows[$key]['output_tax_minor'] += $sign * $line->tax_minor;
            } else {
                $rows[$key]['purchases_taxable_minor'] += $sign * $line->amount_minor;
                $rows[$key]['input_tax_minor'] += $sign * $line->tax_minor;
            }
        }

        $codes = $this->books->query(TaxCode::class, $company)->whereKey(array_filter(array_keys($rows)))->get()->keyBy('id');
        $result = [];
        foreach ($rows as $data) {
            $code = $data['tax_code_id'] === null ? null : ($codes[$data['tax_code_id']] ?? null);
            $result[] = [...$data, 'code' => $code?->code, 'name' => $code?->name, 'rate_bp' => $code?->rate_bp];
        }
        usort($result, fn (array $a, array $b) => [$b['rate_bp'] ?? -1, $a['code'] ?? ''] <=> [$a['rate_bp'] ?? -1, $b['code'] ?? '']);

        $output = array_sum(array_column($result, 'output_tax_minor'));
        $input = array_sum(array_column($result, 'input_tax_minor'));

        return [
            'from' => $from,
            'to' => $to,
            'currency' => $this->books->currency($company),
            'rows' => $result,
            'output_tax_minor' => $output,
            'input_tax_minor' => $input,
            'payable_minor' => $output - $input,
        ];
    }
}
