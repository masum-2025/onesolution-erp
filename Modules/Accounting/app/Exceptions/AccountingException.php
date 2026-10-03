<?php

namespace Modules\Accounting\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Accounting errors: a translated message (accounting::accounting.errors.*)
 * that says what to do next, and a stable code.
 */
class AccountingException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'accounting::accounting.errors.'.$this->errorCode;
    }

    public static function notCompany(): self
    {
        return new self('not_company', 422);
    }

    public static function notSetUp(): self
    {
        return new self('not_set_up', 409);
    }

    public static function alreadySetUp(): self
    {
        return new self('already_set_up', 409);
    }

    public static function moduleOff(): self
    {
        return new self('module_off', 403);
    }

    public static function noCurrency(): self
    {
        return new self('no_currency', 409);
    }

    public static function currencyNotSupported(string $currency): self
    {
        return new self('currency_not_supported', 422, ['currency' => $currency]);
    }

    public static function accountNotFound(): self
    {
        return new self('account_not_found', 404);
    }

    public static function journalNotFound(): self
    {
        return new self('journal_not_found', 404);
    }

    public static function periodNotFound(): self
    {
        return new self('period_not_found', 404);
    }

    public static function unknownStep(): self
    {
        return new self('unknown_step', 404);
    }

    public static function unknownPostingKey(): self
    {
        return new self('unknown_posting_key', 404);
    }

    /**
     * @param  array<string, mixed>  $current  The record as it is now.
     */
    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function accountInUse(): self
    {
        return new self('account_in_use', 409);
    }

    public static function accountNotEmpty(): self
    {
        return new self('account_not_empty', 409);
    }

    public static function accountMapped(): self
    {
        return new self('account_mapped', 409);
    }

    public static function accountHasChildren(): self
    {
        return new self('account_has_children', 409);
    }

    public static function notEditable(): self
    {
        return new self('not_editable', 409);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 409);
    }

    public static function notPosted(): self
    {
        return new self('not_posted', 409);
    }

    public static function alreadyReversed(): self
    {
        return new self('already_reversed', 409);
    }

    public static function reversalOfReversal(): self
    {
        return new self('reversal_of_reversal', 422);
    }

    public static function ownJournal(): self
    {
        return new self('own_journal', 403);
    }

    public static function notSubmitter(): self
    {
        return new self('not_submitter', 403);
    }

    public static function unbalanced(int $debit, int $credit): self
    {
        return new self('unbalanced', 422, extra: ['debit_minor' => $debit, 'credit_minor' => $credit]);
    }

    public static function tooFewLines(): self
    {
        return new self('too_few_lines', 422);
    }

    public static function noPeriod(string $date): self
    {
        return new self('no_period', 422, ['date' => $date]);
    }

    public static function periodClosed(string $from, string $to): self
    {
        return new self('period_closed', 422, ['from' => $from, 'to' => $to]);
    }

    public static function tooOld(int $days): self
    {
        return new self('too_old', 422, ['days' => (string) $days]);
    }

    public static function tooFarAhead(int $days): self
    {
        return new self('too_far_ahead', 422, ['days' => (string) $days]);
    }

    public static function postingAccountMissing(string $key): self
    {
        return new self('posting_account_missing', 422, ['key' => $key]);
    }

    public static function postingAccountType(string $type): self
    {
        return new self('posting_account_type', 422, ['type' => $type]);
    }

    public static function periodAlreadyClosed(): self
    {
        return new self('period_already_closed', 409);
    }

    public static function periodNotClosed(): self
    {
        return new self('period_not_closed', 409);
    }

    public static function pendingInPeriod(int $count): self
    {
        return new self('pending_in_period', 409, ['count' => (string) $count]);
    }

    public static function yearNotNext(string $expected): self
    {
        return new self('year_not_next', 422, ['date' => $expected]);
    }

    public static function rangeTooLarge(int $max): self
    {
        return new self('range_too_large', 422, ['max' => (string) $max]);
    }
}
