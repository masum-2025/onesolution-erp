<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\PatientBilling\Events\BillFinalized;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\DiscountRequest;
use Modules\PatientBilling\Models\Panel;
use Modules\PatientBilling\Models\Service;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * A patient's bill from start to end. Open: services are added at today's
 * price (a wrong line is voided, never deleted), a corporate or panel client
 * may be chosen, and a discount given (above the company's limit it waits
 * for someone else's approval). Final: totals are fixed, the bill goes into
 * the books and the patient's history, and what is still due belongs to the
 * patient or, on a panel bill, to the panel (within its credit limit). An
 * open bill nobody paid may be voided with a reason. Every step is audited.
 */
class Bills
{
    public function __construct(
        private PatientBillingStore $store,
        private BillingPostings $postings,
        private PatientDirectory $patients,
        private AuditLogger $audit,
    ) {}

    public function create(Organization $company, Organization $unit, string $patientId, ?string $panelId, ?string $opId, User $actor): Bill
    {
        if ($opId !== null && ($done = $this->store->query(Bill::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        $patient = $this->patients->find($company, $patientId) ?? throw PatientBillingException::notFound('patient');
        $panel = $panelId === null ? null : $this->panel($company, $panelId);

        return $this->store->transaction($company, function () use ($company, $unit, $patient, $panel, $opId, $actor) {
            $bill = new Bill([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'number' => $this->store->nextNumber($company, 'bill'),
                'patient_id' => $patient->id, 'panel_id' => $panel?->getKey(), 'currency' => $this->store->currency($company),
                'prices_include_tax' => (bool) $this->store->rule('prices_include_tax', $unit), 'created_by' => $actor->getKey(), 'op_id' => $opId,
            ]);
            $bill->save();
            $this->audit->record('patient_billing.bill_opened', $bill, new: ['number' => $bill->number], actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
    }

    /**
     * A service on an open bill. With a source (another department's order), the same source is added once.
     *
     * @param  array{module: string, type: string, id: string}|null  $source
     */
    public function addLine(Organization $company, Bill $bill, string $serviceId, int $quantityMilli, ?User $actor, ?array $source = null): BillLine
    {
        if ($source !== null && ($done = $this->sourceLine($company, $source)) !== null) {
            return $done;
        }
        $service = $this->store->query(Service::class, $company)->whereKey($serviceId)->first() ?? throw PatientBillingException::notFound('service');
        if (! $service->is_active) {
            throw PatientBillingException::because('service_inactive');
        }
        if ($quantityMilli <= 0) {
            throw PatientBillingException::because('bad_quantity');
        }
        $rate = $service->tax_code_id === null ? 0 : ($this->postings->taxRates($company, [$service->tax_code_id])[$service->tax_code_id] ?? 0);

        return $this->store->transaction($company, function () use ($company, $bill, $service, $quantityMilli, $rate, $actor, $source) {
            $bill = $this->lockOpen($company, $bill);
            $line = new BillLine([
                'organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), 'service_id' => $service->getKey(),
                'description' => $service->texts('name'), 'quantity_milli' => $quantityMilli, 'unit_price_minor' => $service->price_minor,
                'gross_minor' => BillTotals::gross($quantityMilli, $service->price_minor), 'tax_rate_bp' => $rate,
                'source_module' => $source['module'] ?? null, 'source_type' => $source['type'] ?? null, 'source_id' => $source['id'] ?? null,
                'created_by' => $actor?->getKey() ?? $bill->created_by,
            ]);
            $line->save();
            $this->recalculate($company, $bill);

            return $line->refresh();
        });
    }

    public function voidLine(Organization $company, Bill $bill, string $lineId, User $actor): Bill
    {
        return $this->store->transaction($company, function () use ($company, $bill, $lineId, $actor) {
            $bill = $this->lockOpen($company, $bill);
            $line = $this->store->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->whereKey($lineId)->whereNull('voided_at')->first()
                ?? throw PatientBillingException::notFound('line');
            $line->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $actor->getKey()])->save();
            $this->recalculate($company, $bill);
            $this->audit->record('patient_billing.line_voided', $bill, new: ['number' => $bill->number, 'line' => $line->getKey(), 'gross_minor' => $line->gross_minor], actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
    }

    public function setPanel(Organization $company, Bill $bill, ?string $panelId, int $baseVersion, User $actor): Bill
    {
        $panel = $panelId === null ? null : $this->panel($company, $panelId);

        return $this->store->transaction($company, function () use ($company, $bill, $panel, $baseVersion, $actor) {
            $bill = $this->lockOpen($company, $bill, $baseVersion);
            $bill->panel_id = $panel?->getKey();
            $this->recalculate($company, $bill);
            $this->audit->record('patient_billing.panel_set', $bill, new: ['number' => $bill->number, 'panel_id' => $bill->panel_id], actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
    }

    /**
     * A discount (an amount) with a reason: within the limit (rule
     * patient_billing.discount_approval_above_percent of the subtotal) it applies
     * at once; above it, a request waits for someone else.
     *
     * @return array{0: Bill, 1: DiscountRequest|null}
     */
    public function discount(Organization $company, Organization $unit, Bill $bill, int $amountMinor, string $reason, User $actor): array
    {
        return $this->store->transaction($company, function () use ($company, $unit, $bill, $amountMinor, $reason, $actor) {
            $bill = $this->lockOpen($company, $bill);
            if ($this->store->query(DiscountRequest::class, $company)->where('bill_id', $bill->getKey())->where('status', DiscountRequest::PENDING)->exists()) {
                throw PatientBillingException::because('discount_waiting', 409);
            }
            $room = $bill->subtotal_minor - $bill->panel_discount_minor;
            if ($amountMinor < 0 || $amountMinor > $room) {
                throw PatientBillingException::because('discount_too_big', 422, ['max_minor' => max(0, $room)]);
            }
            $percentBp = $bill->subtotal_minor === 0 ? 0 : intdiv($amountMinor * 10000 + intdiv($bill->subtotal_minor, 2), $bill->subtotal_minor);
            $limitBp = (int) $this->store->rule('discount_approval_above_percent', $unit) * 100;
            if ($percentBp > $limitBp) {
                $request = new DiscountRequest([
                    'organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), 'amount_minor' => $amountMinor,
                    'percent_bp' => $percentBp, 'reason' => $reason, 'requested_by' => $actor->getKey(),
                ]);
                $request->save();
                $this->audit->record('patient_billing.discount_requested', $request, new: ['number' => $bill->number, 'amount_minor' => $amountMinor, 'percent_bp' => $percentBp],
                    reason: $reason, actor: $actor, organizationId: $company->getKey());

                return [$bill, $request];
            }
            $this->applyDiscount($company, $bill, $amountMinor, $reason, $actor);

            return [$bill, null];
        });
    }

    public function decideDiscount(Organization $company, DiscountRequest $request, bool $approve, ?string $note, User $actor): DiscountRequest
    {
        return $this->store->transaction($company, function () use ($company, $request, $approve, $note, $actor) {
            $request = $this->store->query(DiscountRequest::class, $company)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($request->status !== DiscountRequest::PENDING) {
                throw PatientBillingException::because('not_pending', 409);
            }
            if ($request->requested_by === $actor->getKey()) {
                throw PatientBillingException::because('own_request', 403);
            }
            $bill = $this->store->query(Bill::class, $company)->whereKey($request->bill_id)->lockForUpdate()->firstOrFail();
            if ($approve && ! $bill->isOpen()) {
                throw PatientBillingException::because('bill_not_open', 409);
            }
            $request->fill(['status' => $approve ? DiscountRequest::APPROVED : DiscountRequest::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => CarbonImmutable::now(), 'note' => $note])->save();
            if ($approve) {
                $this->applyDiscount($company, $bill, $request->amount_minor, $request->reason, $actor);
            }
            $this->audit->record($approve ? 'patient_billing.discount_approved' : 'patient_billing.discount_rejected', $request,
                new: ['number' => $bill->number, 'amount_minor' => $request->amount_minor], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $request;
        });
    }

    public function finalize(Organization $company, Organization $unit, Bill $bill, int $baseVersion, User $actor): Bill
    {
        $bill = $this->store->transaction($company, function () use ($company, $unit, $bill, $baseVersion, $actor) {
            $bill = $this->lockOpen($company, $bill, $baseVersion);
            $this->recalculate($company, $bill);
            if (! $this->store->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->whereNull('voided_at')->exists()) {
                throw PatientBillingException::because('bill_empty');
            }
            if ($this->store->query(DiscountRequest::class, $company)->where('bill_id', $bill->getKey())->where('status', DiscountRequest::PENDING)->exists()) {
                throw PatientBillingException::because('discount_waiting', 409);
            }
            $due = $bill->dueMinor();
            if ($bill->panel_id !== null) {
                $this->checkCreditLimit($company, $bill, $due);
            } elseif ($due > 0 && ! $this->store->rule('allow_due', $unit)) {
                throw PatientBillingException::because('must_pay_in_full', 422, ['due_minor' => $due]);
            }
            $bill->fill([
                'status' => Bill::FINAL, 'panel_due_minor' => $bill->panel_id === null ? 0 : $due,
                'finalized_at' => CarbonImmutable::now(), 'finalized_by' => $actor->getKey(),
            ]);
            $bill->version++;
            $bill->save();

            $patientShare = $bill->total_minor - $bill->panel_due_minor;
            $journal = $this->postings->post($company, "patient-bill-{$bill->getKey()}", $this->store->today($company), __('patient_billing::messages.narration.bill', ['number' => $bill->number]),
                'bill', $bill->getKey(), $bill->currency, [
                    LedgerLine::debit('patient_billing.receivable', $patientShare, $bill->unit_id),
                    LedgerLine::debit('patient_billing.panel_receivable', $bill->panel_due_minor, $bill->unit_id),
                    LedgerLine::credit('patient_billing.income', $bill->total_minor - $bill->tax_minor, $bill->unit_id),
                    LedgerLine::credit('patient_billing.tax_output', $bill->tax_minor),
                ]);
            $bill->forceFill(['journal_id' => $journal])->save();
            $this->audit->record('patient_billing.bill_finalized', $bill, new: ['number' => $bill->number, 'total_minor' => $bill->total_minor, 'due_minor' => $due, 'panel_id' => $bill->panel_id],
                actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
        $this->patients->addToTimeline($company, $bill->patient_id, 'patient_billing', 'bill', $bill->getKey(), [
            'en' => __('patient_billing::messages.timeline.bill', ['number' => $bill->number], 'en'),
            'bn' => __('patient_billing::messages.timeline.bill', ['number' => $bill->number], 'bn'),
        ], CarbonImmutable::now());
        BillFinalized::dispatch($company->getKey(), $bill->getKey(), $bill->patient_id, $bill->total_minor, $bill->currency);

        return $bill;
    }

    public function void(Organization $company, Bill $bill, string $reason, int $baseVersion, User $actor): Bill
    {
        return $this->store->transaction($company, function () use ($company, $bill, $reason, $baseVersion, $actor) {
            $bill = $this->lockOpen($company, $bill, $baseVersion);
            if ($bill->paid_minor > 0) {
                throw PatientBillingException::because('bill_paid', 409);
            }
            $this->store->query(DiscountRequest::class, $company)->where('bill_id', $bill->getKey())->where('status', DiscountRequest::PENDING)
                ->update(['status' => DiscountRequest::REJECTED, 'decided_at' => CarbonImmutable::now(), 'note' => $reason]);
            $bill->fill(['status' => Bill::VOID, 'void_reason' => $reason]);
            $bill->version++;
            $bill->save();
            $this->audit->record('patient_billing.bill_voided', $bill, new: ['number' => $bill->number], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
    }

    /** The patient's open bill at the unit, or a new one (other departments' charges land there). */
    public function openFor(Organization $company, Organization $unit, string $patientId, User $actor): Bill
    {
        $patient = $this->patients->find($company, $patientId) ?? throw PatientBillingException::notFound('patient');

        return $this->store->query(Bill::class, $company)->where('patient_id', $patient->id)->where('unit_id', $unit->getKey())->where('status', Bill::OPEN)
            ->orderBy('created_at')->first()
            ?? $this->create($company, $unit, $patient->id, null, null, $actor);
    }

    /** Lines and bill figures again from the active lines, the panel and the discount. */
    public function recalculate(Organization $company, Bill $bill): void
    {
        $lines = $this->store->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->whereNull('voided_at')->orderBy('created_at')->orderBy('id')->get();
        $percent = $bill->panel_id === null ? 0 : (int) ($this->store->query(Panel::class, $company)->whereKey($bill->panel_id)->value('discount_percent') ?? 0);
        $totals = BillTotals::of($lines->map(fn (BillLine $line) => [
            'quantity_milli' => $line->quantity_milli, 'unit_price_minor' => $line->unit_price_minor, 'tax_rate_bp' => $line->tax_rate_bp,
        ])->all(), $percent, $bill->discount_minor, $bill->prices_include_tax);
        foreach ($lines->values() as $index => $line) {
            $figures = $totals['lines'][$index];
            $line->forceFill(['gross_minor' => $figures['gross'], 'discount_minor' => $figures['discount'], 'tax_minor' => $figures['tax'], 'total_minor' => $figures['total']]);
            if ($line->isDirty()) {
                $line->save();
            }
        }
        $bill->fill([
            'subtotal_minor' => $totals['subtotal'], 'panel_discount_minor' => $totals['panel_discount'], 'discount_minor' => $totals['discount'],
            'tax_minor' => $totals['tax'], 'total_minor' => $totals['total'],
        ]);
        if ($bill->isDirty()) {
            $bill->version++;
        }
        $bill->save();
    }

    private function applyDiscount(Organization $company, Bill $bill, int $amountMinor, string $reason, User $actor): void
    {
        $bill->fill(['discount_minor' => $amountMinor, 'discount_reason' => $reason]);
        $this->recalculate($company, $bill);
        if ($bill->paid_minor > $bill->total_minor) {
            throw PatientBillingException::because('discount_below_paid', 422, ['paid_minor' => $bill->paid_minor]);
        }
        $this->audit->record('patient_billing.discount_given', $bill, new: ['number' => $bill->number, 'amount_minor' => $amountMinor], reason: $reason, actor: $actor, organizationId: $company->getKey());
    }

    private function checkCreditLimit(Organization $company, Bill $bill, int $due): void
    {
        $panel = $this->store->query(Panel::class, $company)->whereKey($bill->panel_id)->firstOrFail();
        if ($panel->credit_limit_minor === null) {
            return;
        }
        $owed = (int) $this->store->query(Bill::class, $company)->where('panel_id', $panel->getKey())->where('status', Bill::FINAL)
            ->get(['total_minor', 'paid_minor'])->sum(fn (Bill $other) => $other->dueMinor());
        if ($owed + $due > $panel->credit_limit_minor) {
            throw PatientBillingException::because('panel_over_limit', 422, ['left_minor' => max(0, $panel->credit_limit_minor - $owed)]);
        }
    }

    private function lockOpen(Organization $company, Bill $bill, ?int $baseVersion = null): Bill
    {
        $bill = $this->store->query(Bill::class, $company)->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
        if (! $bill->isOpen()) {
            throw PatientBillingException::because('bill_not_open', 409);
        }
        if ($baseVersion !== null && $bill->version !== $baseVersion) {
            throw PatientBillingException::versionConflict(['version' => $bill->version]);
        }

        return $bill;
    }

    private function panel(Organization $company, string $panelId): Panel
    {
        $panel = $this->store->query(Panel::class, $company)->whereKey($panelId)->first() ?? throw PatientBillingException::notFound('panel');
        if (! $panel->is_active) {
            throw PatientBillingException::because('panel_inactive');
        }

        return $panel;
    }

    /** @param  array{module: string, type: string, id: string}  $source */
    private function sourceLine(Organization $company, array $source): ?BillLine
    {
        return $this->store->query(BillLine::class, $company)
            ->where('source_module', $source['module'])->where('source_type', $source['type'])->where('source_id', $source['id'])->first();
    }
}
