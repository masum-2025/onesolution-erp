<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\PatientBilling\Events\PaymentReceived;
use Modules\PatientBilling\Events\RefundMade;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\Payment;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Money in and out, each a separate record (append-only; one op id makes one
 * record): a payment on an open or final bill, full or part, never more than
 * is due; an advance before any bill; paying from the advance; a refund of
 * money paid on a bill (never more than was kept), in cash, back to the card
 * or wallet, or onto the advance. Cash needs the cashier's open day (rule
 * patient_billing.require_cash_day). Each goes into the books.
 */
class Payments
{
    public function __construct(
        private PatientBillingStore $store,
        private BillingPostings $postings,
        private CashDays $days,
        private PatientDirectory $patients,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{op_id: string, bill_id?: string|null, patient_id?: string|null, kind: string, method: string, amount_minor: int, reference?: string|null}  $data
     */
    public function receive(Organization $company, Organization $unit, array $data, User $actor): Payment
    {
        if (($done = $this->done($company, $data['op_id'])) !== null) {
            return $done;
        }
        $advance = $data['kind'] === Payment::ADVANCE;
        if ($advance && $data['method'] === 'advance') {
            throw PatientBillingException::because('bad_method');
        }
        $patientId = $advance
            ? ($this->patients->find($company, (string) ($data['patient_id'] ?? ''))?->id ?? throw PatientBillingException::notFound('patient'))
            : null;
        $day = $this->cashDay($company, $unit, $data['method'], $actor);

        $payment = $this->store->transaction($company, function () use ($company, $unit, $data, $advance, $patientId, $day, $actor) {
            $bill = null;
            if (! $advance) {
                $bill = $this->store->query(Bill::class, $company)->whereKey((string) ($data['bill_id'] ?? ''))->lockForUpdate()->first() ?? throw PatientBillingException::notFound('bill');
                if ($bill->status === Bill::VOID) {
                    throw PatientBillingException::because('bill_not_open', 409);
                }
                if ($data['amount_minor'] > $bill->dueMinor()) {
                    throw PatientBillingException::because('overpay', 422, ['due_minor' => $bill->dueMinor()]);
                }
                if ($data['method'] === 'advance' && $data['amount_minor'] > $this->advanceBalance($company, $bill->patient_id)) {
                    throw PatientBillingException::because('advance_short', 422, ['balance_minor' => $this->advanceBalance($company, $bill->patient_id)]);
                }
            }
            $payment = $this->record($company, $unit, [
                'kind' => $data['kind'], 'method' => $data['method'], 'amount_minor' => $data['amount_minor'], 'reference' => $data['reference'] ?? null,
                'bill_id' => $bill?->getKey(), 'patient_id' => $bill?->patient_id ?? $patientId, 'op_id' => $data['op_id'],
            ], $day?->getKey(), $actor, 'receipt');
            if ($bill !== null) {
                $bill->paid_minor += $payment->amount_minor;
                $bill->version++;
                $bill->save();
            }
            // Before finalizing (and for the patient's share) the money settles the patient; once a panel bill is final, the panel.
            $settles = $bill !== null && $bill->status === Bill::FINAL && $bill->panel_due_minor > 0 ? 'patient_billing.panel_receivable' : 'patient_billing.receivable';
            $this->post($company, $payment, $advance
                ? [LedgerLine::debit(BillingPostings::METHOD_KEYS[$payment->method], $payment->amount_minor, $payment->unit_id), LedgerLine::credit('patient_billing.advance', $payment->amount_minor)]
                : [LedgerLine::debit(BillingPostings::METHOD_KEYS[$payment->method], $payment->amount_minor, $payment->unit_id), LedgerLine::credit($settles, $payment->amount_minor, $payment->unit_id)]);
            $this->audit->record('patient_billing.payment_received', $payment, new: [
                'number' => $payment->number, 'kind' => $payment->kind, 'method' => $payment->method, 'amount_minor' => $payment->amount_minor, 'bill' => $bill?->number,
            ], actor: $actor, organizationId: $company->getKey());

            return $payment;
        });
        PaymentReceived::dispatch($company->getKey(), $payment->getKey(), $payment->bill_id, $payment->kind, $payment->method, $payment->amount_minor, $payment->currency);

        return $payment;
    }

    /**
     * Money back on a bill (patient_billing.approve), with a reason.
     *
     * @param  array{op_id: string, method: string, amount_minor: int, reason: string, reference?: string|null}  $data
     */
    public function refund(Organization $company, Organization $unit, Bill $bill, array $data, User $actor): Payment
    {
        if (($done = $this->done($company, $data['op_id'])) !== null) {
            return $done;
        }
        $day = $this->cashDay($company, $unit, $data['method'], $actor);

        $refund = $this->store->transaction($company, function () use ($company, $unit, $bill, $data, $day, $actor) {
            $bill = $this->store->query(Bill::class, $company)->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            if ($bill->status === Bill::VOID) {
                throw PatientBillingException::because('bill_not_open', 409);
            }
            if ($data['amount_minor'] > $bill->keptMinor()) {
                throw PatientBillingException::because('refund_too_big', 422, ['max_minor' => $bill->keptMinor()]);
            }
            $refund = $this->record($company, $unit, [
                'kind' => Payment::REFUND, 'method' => $data['method'], 'amount_minor' => $data['amount_minor'], 'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'], 'bill_id' => $bill->getKey(), 'patient_id' => $bill->patient_id, 'op_id' => $data['op_id'],
            ], $day?->getKey(), $actor, 'refund');
            $bill->refunded_minor += $refund->amount_minor;
            $bill->version++;
            $bill->save();
            $this->post($company, $refund, [
                LedgerLine::debit('patient_billing.refunds', $refund->amount_minor, $refund->unit_id),
                LedgerLine::credit(BillingPostings::METHOD_KEYS[$refund->method], $refund->amount_minor, $refund->unit_id),
            ]);
            $this->audit->record('patient_billing.refund_made', $refund, new: [
                'number' => $refund->number, 'method' => $refund->method, 'amount_minor' => $refund->amount_minor, 'bill' => $bill->number,
            ], reason: $data['reason'], actor: $actor, organizationId: $company->getKey());

            return $refund;
        });
        RefundMade::dispatch($company->getKey(), $refund->getKey(), $refund->bill_id, $refund->method, $refund->amount_minor, $refund->currency);

        return $refund;
    }

    /** What the patient has on advance: deposits + refunds onto the advance − what was paid from it. */
    public function advanceBalance(Organization $company, string $patientId): int
    {
        $rows = $this->store->query(Payment::class, $company)->where('patient_id', $patientId)->get(['kind', 'method', 'amount_minor']);

        return (int) $rows->where('kind', Payment::ADVANCE)->sum('amount_minor')
            + (int) $rows->where('kind', Payment::REFUND)->where('method', 'advance')->sum('amount_minor')
            - (int) $rows->where('kind', Payment::PAYMENT)->where('method', 'advance')->sum('amount_minor');
    }

    private function done(Organization $company, string $opId): ?Payment
    {
        return $this->store->query(Payment::class, $company)->where('op_id', $opId)->first();
    }

    private function cashDay(Organization $company, Organization $unit, string $method, User $actor)
    {
        $day = $this->days->openDay($company, $unit, $actor);
        if ($method === 'cash' && $day === null && $this->store->rule('require_cash_day', $unit)) {
            throw PatientBillingException::because('no_cash_day', 409);
        }

        return $method === 'cash' ? $day : null;
    }

    /** @param  array<string, mixed>  $values */
    private function record(Organization $company, Organization $unit, array $values, ?string $dayId, User $actor, string $numberKind): Payment
    {
        $payment = new Payment([
            ...$values,
            'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'number' => $this->store->nextNumber($company, $numberKind),
            'currency' => $this->store->currency($company), 'cash_day_id' => $dayId, 'received_by' => $actor->getKey(), 'received_at' => CarbonImmutable::now(),
        ]);
        $payment->save();

        return $payment;
    }

    /** @param  list<LedgerLine>  $lines */
    private function post(Organization $company, Payment $payment, array $lines): void
    {
        $journal = $this->postings->post($company, "patient-payment-{$payment->getKey()}", $this->store->today($company),
            __('patient_billing::messages.narration.'.$payment->kind, ['number' => $payment->number]), $payment->kind, $payment->getKey(), $payment->currency, $lines);
        if ($journal !== null) {
            $payment->journal_id = $journal;
            $payment->save();
        }
    }
}
