<?php

namespace Modules\Accounting\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Models\FiscalYear;

/**
 * Journal numbers from the company's rule accounting.journal_number_format,
 * e.g. "JV-{YYYY}-{SEQ:5}" -> JV-2026-00012. {YYYY}/{YY} are the year the
 * fiscal year starts in, {FY} its name ("2026-27"). One running number per
 * fiscal year, taken under a row lock when a journal is posted (drafts get
 * none, so posted numbers have no gaps). Call inside the posting transaction.
 */
class JournalNumbers
{
    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function next(Organization $company, FiscalYear $year): string
    {
        $format = (string) $this->rules->get('accounting.journal_number_format', $this->contexts->forOrganization($company));

        $sequences = $this->books->table('acc_sequences', $company);
        $key = ['organization_id' => $company->getKey(), 'fiscal_year_id' => $year->getKey()];
        $row = (clone $sequences)->where($key)->lockForUpdate()->first();
        $number = (int) $row->last + 1;
        (clone $sequences)->where('id', $row->id)->update(['last' => $number, 'updated_at' => now()]);

        return (string) preg_replace_callback('/\{([A-Z]+)(?::(\d+))?\}/', fn (array $match) => match ($match[1]) {
            'YYYY' => $year->starts_on->format('Y'),
            'YY' => $year->starts_on->format('y'),
            'FY' => $year->name,
            'SEQ' => str_pad((string) $number, (int) ($match[2] ?? 1), '0', STR_PAD_LEFT),
            default => $match[0],
        }, $format);
    }
}
