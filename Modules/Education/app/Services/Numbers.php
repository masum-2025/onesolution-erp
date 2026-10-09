<?php

namespace Modules\Education\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Education\Models\Sequence;

/**
 * Student codes and admission numbers, counted per year.
 *
 * Student codes follow the rule education.student_code_format, e.g.
 * "{YYYY}{SEQ:4}" -> 20260001, "{PROGRAM}-{YY}-{SEQ:3}" -> SSC-26-001.
 * Admission numbers: the prefix from education.number_prefixes, the year
 * and a number ("ADM-2026-0007"). Call inside the caller's transaction:
 * the counter row is locked.
 */
class Numbers
{
    public function __construct(private Education $education, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function studentCode(Organization $company, int $year, string $programCode): string
    {
        $format = (string) $this->rules->get('education.student_code_format', $this->contexts->forOrganization($company));
        $number = $this->next($company, 'student', $year);

        return preg_replace_callback('/\{(YYYY|YY|PROGRAM|SEQ(?::(\d))?)\}/', fn (array $match) => match (true) {
            $match[1] === 'YYYY' => (string) $year,
            $match[1] === 'YY' => substr((string) $year, -2),
            $match[1] === 'PROGRAM' => $programCode,
            default => str_pad((string) $number, (int) ($match[2] ?? 4), '0', STR_PAD_LEFT),
        }, $format);
    }

    public function admissionNumber(Organization $company, int $year): string
    {
        $prefixes = (array) $this->rules->get('education.number_prefixes', $this->contexts->forOrganization($company));

        return ($prefixes['admission'] ?? 'ADM').'-'.$year.'-'.str_pad((string) $this->next($company, 'admission', $year), 4, '0', STR_PAD_LEFT);
    }

    public function promotionNumber(Organization $company, int $year): string
    {
        $prefixes = (array) $this->rules->get('education.number_prefixes', $this->contexts->forOrganization($company));

        return ($prefixes['promotion'] ?? 'PRM').'-'.$year.'-'.str_pad((string) $this->next($company, 'promotion', $year), 4, '0', STR_PAD_LEFT);
    }

    private function next(Organization $company, string $kind, int $year): int
    {
        $sequence = $this->education->query(Sequence::class, $company)->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'kind' => $kind, 'year' => $year, 'last_number' => 0]);
        }
        $sequence->last_number++;
        $sequence->save();

        return $sequence->last_number;
    }
}
