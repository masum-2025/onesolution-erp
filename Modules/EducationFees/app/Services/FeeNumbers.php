<?php

namespace Modules\EducationFees\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\EducationFees\Models\Sequence;

/**
 * Bill numbers from the rule education_fees.bill_number_format, counted per
 * year: "FEE-{YYYY}-{SEQ:6}" -> FEE-2026-000042. Call inside the caller's
 * transaction: the counter row is locked.
 */
class FeeNumbers
{
    public function __construct(private FeeOffice $office, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function bill(Organization $company, int $year): string
    {
        return $this->format($company, 'education_fees.bill_number_format', 'bill', $year);
    }

    /** Receipt numbers from rule education_fees.receipt_number_format, counted per year. */
    public function receipt(Organization $company, int $year): string
    {
        return $this->format($company, 'education_fees.receipt_number_format', 'receipt', $year);
    }

    private function format(Organization $company, string $rule, string $kind, int $year): string
    {
        $format = (string) $this->rules->get($rule, $this->contexts->forOrganization($company));
        $number = $this->next($company, $kind, $year);

        return preg_replace_callback('/\{(YYYY|YY|SEQ(?::(\d))?)\}/', fn (array $match) => match (true) {
            $match[1] === 'YYYY' => (string) $year,
            $match[1] === 'YY' => substr((string) $year, -2),
            default => str_pad((string) $number, (int) ($match[2] ?? 6), '0', STR_PAD_LEFT),
        }, $format);
    }

    private function next(Organization $company, string $kind, int $year): int
    {
        $sequence = $this->office->query(Sequence::class, $company)->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'kind' => $kind, 'year' => $year, 'last_number' => 0]);
        }
        $sequence->last_number++;
        $sequence->save();

        return $sequence->last_number;
    }
}
