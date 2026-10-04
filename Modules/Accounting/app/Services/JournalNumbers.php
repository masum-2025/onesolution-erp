<?php

namespace Modules\Accounting\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Str;
use Modules\Accounting\Models\FiscalYear;

/**
 * Numbers of journals and documents, from the company's rules:
 * accounting.journal_number_format for journals ("JV-{YYYY}-{SEQ:5}") and
 * accounting.document_number_formats for invoices, bills, credit notes,
 * receipts and payments. {YYYY}/{YY} are the year the fiscal year starts in,
 * {FY} its name ("2026-27"). One running number per fiscal year and kind,
 * taken under a row lock when the record is posted (drafts get none, so
 * posted numbers have no gaps). Call inside the posting transaction.
 */
class JournalNumbers
{
    public const JOURNAL = 'journal';

    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function next(Organization $company, FiscalYear $year, string $kind = self::JOURNAL): string
    {
        $context = $this->contexts->forOrganization($company);
        $format = $kind === self::JOURNAL
            ? (string) $this->rules->get('accounting.journal_number_format', $context)
            : (string) ((array) $this->rules->get('accounting.document_number_formats', $context))[$kind];

        $number = $this->take($company, $year, $kind);

        return (string) preg_replace_callback('/\{([A-Z]+)(?::(\d+))?\}/', fn (array $match) => match ($match[1]) {
            'YYYY' => $year->starts_on->format('Y'),
            'YY' => $year->starts_on->format('y'),
            'FY' => $year->name,
            'SEQ' => str_pad((string) $number, (int) ($match[2] ?? 1), '0', STR_PAD_LEFT),
            default => $match[0],
        }, $format);
    }

    private function take(Organization $company, FiscalYear $year, string $kind): int
    {
        $sequences = $this->books->table('acc_sequences', $company);
        $key = ['organization_id' => $company->getKey(), 'fiscal_year_id' => $year->getKey(), 'kind' => $kind];

        $row = (clone $sequences)->where($key)->lockForUpdate()->first();
        if ($row === null) {
            // The first number of this kind in the year: the year row is locked
            // first, so two postings never both create the sequence.
            $this->books->table('acc_fiscal_years', $company)->where('id', $year->getKey())->lockForUpdate()->first();
            $row = (clone $sequences)->where($key)->lockForUpdate()->first();
            if ($row === null) {
                (clone $sequences)->insert([...$key, 'id' => (string) Str::ulid(), 'last' => 1, 'created_at' => now(), 'updated_at' => now()]);

                return 1;
            }
        }

        $number = (int) $row->last + 1;
        (clone $sequences)->where('id', $row->id)->update(['last' => $number, 'updated_at' => now()]);

        return $number;
    }
}
