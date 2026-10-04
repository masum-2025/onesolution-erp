<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Payments\DecimalAmount;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankFormat;
use Modules\Accounting\Models\BankLine;
use Modules\Accounting\Models\Reconciliation;

/**
 * Statement lines of a bank, wallet or cash account, from a CSV file the
 * bank gives. The person says which columns hold the date, description,
 * reference and amount (one signed column, or money in and money out) and
 * how dates are written; that is remembered for the account. The file is
 * read in memory and never kept. Everything comes in, or nothing (with the
 * rows to fix); lines already in, or dated on or before the last finished
 * reconciliation, are skipped. Amounts become integer minor units with
 * string arithmetic (Bangla digits, thousands separators and "(100.00)"
 * for money out are understood).
 */
class BankStatements
{
    /** Most row problems listed at once. */
    private const MAX_ERRORS = 10;

    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** A usable asset account (cash, bank, wallet) whose statement this is. */
    public function account(Organization $company, string $id): Account
    {
        $account = $this->books->query(Account::class, $company)->whereKey($id)->first() ?? throw AccountingException::accountNotFound();
        if (! $account->isPostable() || $account->type !== AccountType::Asset) {
            throw AccountingException::notAMoneyAccount();
        }

        return $account;
    }

    /**
     * @return array{columns: array<string, string>, date_format: string}|null
     */
    public function format(Organization $company, Account $account): ?array
    {
        $format = $this->books->query(BankFormat::class, $company)->where('account_id', $account->getKey())->first();

        return $format === null ? null : ['columns' => $format->columns, 'date_format' => $format->date_format];
    }

    /**
     * @param  array<string, string|null>  $columns  date, description, reference?, amount? or money_in? / money_out?
     * @return array{added: int, skipped: int}
     */
    public function import(Organization $company, Account $account, UploadedFile $file, array $columns, string $dateFormat, User $actor): array
    {
        $this->books->assertSetUp($company);
        $context = $this->contexts->forOrganization($company);
        [$header, $rows] = $this->read($file, (int) $this->rules->get('accounting.bank_import_max_kb', $context), (int) $this->rules->get('accounting.bank_import_max_rows', $context));

        $columns = array_filter($columns, fn ($name) => is_string($name) && trim($name) !== '');
        $at = $this->positions($header, $columns);
        $currency = $this->books->currency($company);
        $lastReconciled = $this->books->query(Reconciliation::class, $company)->where('account_id', $account->getKey())
            ->where('status', Reconciliation::FINISHED)->max('statement_date');

        $errors = [];
        $lines = [];
        $seen = [];
        $skipped = 0;
        foreach ($rows as $rowNo => $cells) {
            $cell = fn (string $key) => isset($at[$key]) ? trim((string) ($cells[$at[$key]] ?? '')) : '';
            $amount = isset($at['amount'])
                ? ($cell('amount') === '' ? 0 : self::amount($cell('amount'), $currency))
                : $this->inOut($cell('money_in'), $cell('money_out'), $currency);
            // Nothing moved (an opening or closing balance row, a repeated heading): not a statement line.
            if ($amount === 0) {
                $skipped++;

                continue;
            }
            $date = $this->date($cell('date'), $dateFormat);

            if ($date === null) {
                $errors[] = __('accounting::accounting.bank.row_date', ['row' => $rowNo, 'value' => mb_substr($cell('date'), 0, 30), 'format' => $dateFormat]);
            }
            if ($amount === null) {
                $errors[] = __('accounting::accounting.bank.row_amount', ['row' => $rowNo]);
            }
            if (count($errors) >= self::MAX_ERRORS) {
                break;
            }
            if ($date === null || $amount === null) {
                continue;
            }
            // Already covered by a finished reconciliation.
            if (($lastReconciled !== null && $date->toDateString() <= CarbonImmutable::parse($lastReconciled)->toDateString())) {
                $skipped++;

                continue;
            }

            $description = mb_substr($cell('description') !== '' ? $cell('description') : ($cell('reference') !== '' ? $cell('reference') : '—'), 0, 255);
            $reference = $cell('reference') !== '' ? mb_substr($cell('reference'), 0, 100) : null;
            // The same line twice in one file is two lines; the same file twice adds nothing.
            $key = implode('|', [$date->toDateString(), $amount, mb_strtolower($description), (string) $reference]);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $lines[] = [
                'line_date' => $date->toDateString(),
                'description' => $description,
                'reference' => $reference,
                'amount_minor' => $amount,
                'fingerprint' => hash('sha256', $account->getKey().'|'.$key.'|'.$seen[$key]),
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return $this->books->transaction($company, function () use ($company, $account, $columns, $dateFormat, $lines, $skipped, $actor) {
            $known = $this->books->query(BankLine::class, $company)->where('account_id', $account->getKey())
                ->whereIn('fingerprint', array_column($lines, 'fingerprint'))->pluck('fingerprint')->flip();
            $importId = (string) Str::ulid();
            $added = 0;
            foreach ($lines as $line) {
                if (isset($known[$line['fingerprint']])) {
                    $skipped++;

                    continue;
                }
                $row = new BankLine;
                $row->fill([...$line, 'organization_id' => $company->getKey(), 'account_id' => $account->getKey(), 'import_id' => $importId, 'created_by' => $actor->getKey()])->save();
                $added++;
            }

            $format = $this->books->query(BankFormat::class, $company)->where('account_id', $account->getKey())->first() ?? new BankFormat([
                'organization_id' => $company->getKey(), 'account_id' => $account->getKey(),
            ]);
            $format->fill(['columns' => $columns, 'date_format' => $dateFormat])->save();

            $this->audit->record('accounting.bank_imported', $account, new: ['import_id' => $importId, 'added' => $added, 'skipped' => $skipped], actor: $actor, organizationId: $company->getKey());

            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    /** Remove a line that came in by mistake (never one matched or reconciled). */
    public function delete(Organization $company, BankLine $line, User $actor): void
    {
        $this->books->transaction($company, function () use ($company, $line, $actor) {
            $line = $this->books->query(BankLine::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            if ($line->isLocked()) {
                throw AccountingException::bankLineReconciled();
            }
            if ($line->matched_minor !== 0) {
                throw AccountingException::bankLineInUse();
            }

            $this->audit->record('accounting.bank_line_deleted', $line, old: [
                'line_date' => $line->line_date->toDateString(), 'amount_minor' => $line->amount_minor, 'description' => $line->description,
            ], actor: $actor, organizationId: $company->getKey());
            $line->delete();
        });
    }

    /**
     * Header names and the data rows (by line number in the file).
     *
     * @return array{0: list<string>, 1: array<int, list<string>>}
     */
    private function read(UploadedFile $file, int $maxKb, int $maxRows): array
    {
        $fail = fn (string $key, array $replace = []) => throw ValidationException::withMessages(['file' => __("accounting::accounting.bank.{$key}", $replace)]);

        if ($file->getSize() > $maxKb * 1024) {
            $fail('file_size', ['max' => $maxKb]);
        }
        $content = (string) file_get_contents($file->getRealPath());
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $fail('file_type');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $rows = [];
        $lineNo = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $lineNo++;
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($header === null) {
                $header = $cells;

                continue;
            }
            $rows[$lineNo] = $cells;
            if (count($rows) > $maxRows) {
                fclose($stream);
                $fail('too_many_rows', ['max' => $maxRows]);
            }
        }
        fclose($stream);

        if ($header === null || $rows === []) {
            $fail('empty');
        }

        return [$header, $rows];
    }

    /**
     * Where each chosen column sits in the header (names compared without case).
     *
     * @param  list<string>  $header
     * @param  array<string, string>  $columns
     * @return array<string, int>
     */
    private function positions(array $header, array $columns): array
    {
        $lower = array_map(fn (string $name) => mb_strtolower($name), $header);
        $at = [];
        $errors = [];
        foreach ($columns as $key => $name) {
            $index = array_search(mb_strtolower(trim($name)), $lower, true);
            if ($index === false) {
                $errors["columns.{$key}"] = __('accounting::accounting.bank.column_missing', ['column' => $name]);

                continue;
            }
            $at[$key] = $index;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $at;
    }

    private function date(string $text, string $format): ?CarbonImmutable
    {
        if ($text === '') {
            return null;
        }
        // Statements often add the time ("05/10/2026 14:22"): the day is what counts.
        $text = preg_split('/\s+\d{1,2}:\d{2}/', strtr($text, self::DIGITS))[0];
        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, trim($text), 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }
        $problems = CarbonImmutable::getLastErrors();
        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    /** "1,250.50", "-1250.5", "(1,250.50)", "১২৫০", "Tk 1,250" -> signed minor units; '' and junk -> null. */
    public static function amount(string $text, string $currency): ?int
    {
        $text = strtr(trim($text), self::DIGITS);
        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $text, $match)) {
            [$negative, $text] = [true, $match[1]];
        }
        $text = preg_replace('/[\s,\x{00A0}]|BDT|Tk\.?|৳/iu', '', $text) ?? '';
        if (str_starts_with($text, '-') || str_ends_with($text, '-')) {
            [$negative, $text] = [true, trim($text, '-')];
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }
        $minor = DecimalAmount::toMinor($text, $currency);

        return $minor === null ? null : ($negative ? -$minor : $minor);
    }

    /** Money in minus money out; a blank side is nothing. */
    private function inOut(string $in, string $out, string $currency): ?int
    {
        $moneyIn = $in === '' ? 0 : self::amount($in, $currency);
        $moneyOut = $out === '' ? 0 : self::amount($out, $currency);

        return $moneyIn === null || $moneyOut === null ? null : abs($moneyIn) - abs($moneyOut);
    }

    /** Bangla digits as Latin ones. */
    private const DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
}
