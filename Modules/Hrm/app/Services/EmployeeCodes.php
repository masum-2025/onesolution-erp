<?php

namespace Modules\Hrm\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employee codes from the company's rule hrm.employee_code_format, e.g.
 * "EMP-{YYYY}-{SEQ:4}" -> EMP-2026-0007. {UNIT} is the unit's code, {YY}
 * the short year. The running number is per company and year, kept in the
 * client's own database and taken under a row lock (no two hires share one).
 * Call inside the hire's transaction on that database.
 */
class EmployeeCodes
{
    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private TenantDatabases $databases,
        private Units $units,
    ) {}

    public function next(Organization $company, Organization $unit, CarbonImmutable $joinedOn): string
    {
        $format = (string) $this->rules->get('hrm.employee_code_format', $this->contexts->forOrganization($company));
        $sequence = $this->nextNumber($company, (int) $joinedOn->format('Y'));

        return (string) preg_replace_callback('/\{([A-Z]+)(?::(\d+))?\}/', fn (array $match) => match ($match[1]) {
            'YYYY' => $joinedOn->format('Y'),
            'YY' => $joinedOn->format('y'),
            'UNIT', 'BRANCH' => $this->units->codeOf($unit),
            'SEQ' => str_pad((string) $sequence, (int) ($match[2] ?? 1), '0', STR_PAD_LEFT),
            default => $match[0],
        }, $format);
    }

    private function nextNumber(Organization $company, int $year): int
    {
        $table = DB::connection($this->databases->forOrganization($company))->table('hrm_code_sequences');
        $key = ['organization_id' => $company->getKey(), 'year' => $year];

        $row = (clone $table)->where($key)->lockForUpdate()->first();
        if ($row === null) {
            (clone $table)->insert([...$key, 'id' => (string) Str::ulid(), 'last' => 1, 'created_at' => now(), 'updated_at' => now()]);

            return 1;
        }

        (clone $table)->where('id', $row->id)->update(['last' => $row->last + 1, 'updated_at' => now()]);

        return $row->last + 1;
    }
}
