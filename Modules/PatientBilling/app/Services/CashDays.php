<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\PatientBilling\Events\CashDayClosed;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\CashDay;
use Modules\PatientBilling\Models\Payment;

/**
 * Each cashier's day at a branch: opened with a float, closed by counting the
 * cash. Expected = float + cash taken − cash given back. A difference within
 * patient_billing.cash_variance_allowed closes the day; a bigger one waits
 * for a supervisor (patient_billing.close_day), who is never the cashier.
 * Any difference goes into the books when the day is closed.
 */
class CashDays
{
    public function __construct(private PatientBillingStore $store, private BillingPostings $postings, private AuditLogger $audit) {}

    /** The person's open day at the unit, if any. */
    public function openDay(Organization $company, Organization $unit, User $user): ?CashDay
    {
        return $this->store->query(CashDay::class, $company)->where('unit_id', $unit->getKey())->where('user_id', $user->getKey())
            ->where('status', CashDay::OPEN)->first();
    }

    public function open(Organization $company, Organization $unit, int $floatMinor, User $actor): CashDay
    {
        return $this->store->transaction($company, function () use ($company, $unit, $floatMinor, $actor) {
            $open = $this->store->query(CashDay::class, $company)->where('user_id', $actor->getKey())->where('status', CashDay::OPEN)->lockForUpdate()->first();
            if ($open !== null) {
                throw PatientBillingException::because('day_already_open', 409, ['cash_day_id' => $open->getKey()]);
            }
            $day = new CashDay([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'user_id' => $actor->getKey(),
                'currency' => $this->store->currency($company), 'opening_float_minor' => $floatMinor, 'opened_at' => CarbonImmutable::now(),
            ]);
            $day->save();
            $this->audit->record('patient_billing.day_opened', $day, new: ['opening_float_minor' => $floatMinor], actor: $actor, organizationId: $company->getKey());

            return $day;
        });
    }

    /** Float + cash in − cash out so far. */
    public function expected(Organization $company, CashDay $day): int
    {
        $cash = $this->store->query(Payment::class, $company)->where('cash_day_id', $day->getKey())->where('method', 'cash')->get(['kind', 'amount_minor']);

        return $day->opening_float_minor
            + (int) $cash->whereIn('kind', [Payment::PAYMENT, Payment::ADVANCE])->sum('amount_minor')
            - (int) $cash->where('kind', Payment::REFUND)->sum('amount_minor');
    }

    public function close(Organization $company, Organization $unit, CashDay $day, int $countedMinor, int $baseVersion, User $actor): CashDay
    {
        $day = $this->store->transaction($company, function () use ($company, $unit, $day, $countedMinor, $baseVersion, $actor) {
            $day = $this->store->query(CashDay::class, $company)->whereKey($day->getKey())->lockForUpdate()->firstOrFail();
            if ($day->user_id !== $actor->getKey()) {
                throw PatientBillingException::because('not_your_day', 403);
            }
            if ($day->status !== CashDay::OPEN) {
                throw PatientBillingException::because('day_not_open', 409);
            }
            if ($day->version !== $baseVersion) {
                throw PatientBillingException::versionConflict(['version' => $day->version]);
            }
            $expected = $this->expected($company, $day);
            $variance = $countedMinor - $expected;
            $within = abs($variance) <= max(0, (int) $this->store->rule('cash_variance_allowed', $unit));
            $day->fill([
                'closed_at' => CarbonImmutable::now(), 'expected_minor' => $expected, 'counted_minor' => $countedMinor, 'variance_minor' => $variance,
                'status' => $within ? CashDay::CLOSED : CashDay::REVIEW,
            ]);
            $day->version++;
            $day->save();
            if ($within) {
                $this->postVariance($company, $day);
            }
            $this->audit->record('patient_billing.day_closed', $day, new: ['expected_minor' => $expected, 'counted_minor' => $countedMinor, 'variance_minor' => $variance, 'status' => $day->status],
                actor: $actor, organizationId: $company->getKey());

            return $day;
        });
        if ($day->status === CashDay::CLOSED) {
            CashDayClosed::dispatch($company->getKey(), $day->getKey(), (int) $day->variance_minor, $day->currency);
        }

        return $day;
    }

    /** A supervisor accepts a day whose difference was over the limit. */
    public function review(Organization $company, CashDay $day, string $note, User $actor): CashDay
    {
        $day = $this->store->transaction($company, function () use ($company, $day, $note, $actor) {
            $day = $this->store->query(CashDay::class, $company)->whereKey($day->getKey())->lockForUpdate()->firstOrFail();
            if ($day->status !== CashDay::REVIEW) {
                throw PatientBillingException::because('not_pending', 409);
            }
            if ($day->user_id === $actor->getKey()) {
                throw PatientBillingException::because('own_request', 403);
            }
            $day->fill(['status' => CashDay::CLOSED, 'reviewed_by' => $actor->getKey(), 'review_note' => $note]);
            $day->version++;
            $day->save();
            $this->postVariance($company, $day);
            $this->audit->record('patient_billing.day_reviewed', $day, new: ['variance_minor' => $day->variance_minor], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $day;
        });
        CashDayClosed::dispatch($company->getKey(), $day->getKey(), (int) $day->variance_minor, $day->currency);

        return $day;
    }

    private function postVariance(Organization $company, CashDay $day): void
    {
        $variance = (int) $day->variance_minor;
        if ($variance === 0) {
            return;
        }
        $amount = abs($variance);
        $lines = $variance < 0
            ? [LedgerLine::debit('patient_billing.cash_variance', $amount, $day->unit_id), LedgerLine::credit('patient_billing.cash', $amount, $day->unit_id)]
            : [LedgerLine::debit('patient_billing.cash', $amount, $day->unit_id), LedgerLine::credit('patient_billing.cash_variance', $amount, $day->unit_id)];
        $journal = $this->postings->post($company, "patient-cash-day-{$day->getKey()}", $this->store->today($company), __('patient_billing::messages.narration.cash_day'),
            'cash_day', $day->getKey(), $day->currency, $lines);
        $day->forceFill(['journal_id' => $journal])->save();
    }
}
