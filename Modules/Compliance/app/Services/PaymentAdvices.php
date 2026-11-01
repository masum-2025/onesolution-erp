<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Services\Connections;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\PaymentAdvice;
use Modules\Compliance\Models\ReceivedInvoice;

/**
 * Telling a connected provider that an invoice was paid
 * (compliance.payment_advice, optional: only over a business connection).
 * It never moves money anywhere: the provider checks its bank or cash and
 * records the receipt in its own books, or answers that nothing arrived
 * (projects.advice_reply). Someone holding compliance.bills sends it; part
 * payments are fine, but never more than the invoice still owes after the
 * advices still waiting for an answer.
 */
class PaymentAdvices
{
    public const KIND = 'compliance.payment_advice';

    public const REPLY = 'projects.advice_reply';

    public function __construct(
        private Place $place,
        private Connections $connections,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * The advices of these invoices, newest first, by invoice.
     *
     * @param  list<string>  $invoiceIds
     * @return Collection<string, Collection<int, PaymentAdvice>>
     */
    public function forInvoices(Organization $unit, array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return $this->place->query(PaymentAdvice::class, $unit, $this->place->subtreeIds($unit))->whereIn('invoice_id', $invoiceIds)
            ->orderByDesc('paid_on')->orderByDesc('id')->get()->groupBy('invoice_id');
    }

    /** What the invoice owes less the advices still waiting for the provider. */
    public function openFor(ReceivedInvoice $invoice): int
    {
        $waiting = (int) PaymentAdvice::inTenantOf($invoice->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->where('invoice_id', $invoice->getKey())->where('status', PaymentAdvice::SENT)->sum('amount_minor');

        return max(0, $invoice->balance_minor - $waiting);
    }

    /**
     * @param  array{amount_minor: int, paid_on: string, method: string, reference?: string|null, note?: string|null, op_id: string}  $data
     */
    public function advise(Organization $unit, ReceivedInvoice $invoice, array $data, User $actor): PaymentAdvice
    {
        $factory = Organization::query()->findOrFail($invoice->organization_id);
        $existing = PaymentAdvice::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $factory->getKey())->where('op_id', $data['op_id'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $connection = $this->connections->find($invoice->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ConnectionException::notActive();
        }
        if ($data['paid_on'] > $this->place->today($this->place->companyOf($factory))->toDateString()) {
            throw ComplianceException::adviceInFuture();
        }

        try {
            return $this->place->transaction($unit, function () use ($invoice, $data, $actor, $connection, $factory) {
                /** @var ReceivedInvoice $fresh */
                $fresh = ReceivedInvoice::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
                if ($fresh->provider_status === 'void') {
                    throw ComplianceException::adviceInvoiceVoid();
                }
                $open = $this->openFor($fresh);
                if ($open <= 0) {
                    throw ComplianceException::invoiceNothingOwed();
                }
                if ($data['amount_minor'] > $open) {
                    throw ComplianceException::adviceMoreThanOwed(MoneyText::format($open, $fresh->currency_code, app()->getLocale()));
                }

                $advice = PaymentAdvice::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)->create([
                    'organization_id' => $factory->getKey(),
                    'invoice_id' => $fresh->getKey(),
                    'connection_id' => $fresh->connection_id,
                    'amount_minor' => $data['amount_minor'],
                    'currency_code' => $fresh->currency_code,
                    'paid_on' => $data['paid_on'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'status' => PaymentAdvice::SENT,
                    'op_id' => $data['op_id'],
                    'sent_by' => $actor->getKey(),
                    'version' => 1,
                ]);
                $this->connections->send($connection, self::KIND, $factory, $connection->provider_id, [
                    'advice_id' => $advice->getKey(),
                    'invoice_id' => $fresh->provider_invoice_id,
                    'number' => $fresh->number,
                    'amount_minor' => $advice->amount_minor,
                    'currency_code' => $advice->currency_code,
                    'paid_on' => $advice->paid_on->toDateString(),
                    'method' => $advice->method,
                    'reference' => $advice->reference,
                    'note' => $advice->note,
                    'by' => $actor->name,
                ], [], 'advice-'.$advice->getKey(), $actor);
                $this->audit->record('compliance.payment_advised', $advice, new: ['invoice' => $fresh->number, 'amount_minor' => $advice->amount_minor, 'currency' => $advice->currency_code, 'paid_on' => $advice->paid_on->toDateString()],
                    actor: $actor, organizationId: $factory->getKey());

                return $advice;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The same tap arrived twice at once: the other one was saved.
            return PaymentAdvice::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $factory->getKey())->where('op_id', $data['op_id'])->first() ?? throw $exception;
        }
    }

    /** projects.advice_reply: the provider's answer, once, only for an advice this very connection carried. */
    public function receiveReply(ConnectionDelivery $delivery): ?string
    {
        $payload = $delivery->payload;
        $status = $payload['status'] ?? null;
        if (! in_array($status, [PaymentAdvice::RECORDED, PaymentAdvice::NOT_RECEIVED], true)) {
            return null;
        }
        $advice = PaymentAdvice::query()->withoutGlobalScope(OrganizationScope::class)->whereKey((string) ($payload['advice_id'] ?? ''))
            ->where('connection_id', $delivery->connectionId)->first();
        if ($advice === null) {
            return null;
        }
        if ($advice->status !== PaymentAdvice::SENT) {
            return $advice->getKey();
        }
        $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500) ?: null;
        $advice->forceFill(['status' => $status, 'reply_reason' => $reason, 'replied_at' => now(), 'version' => $advice->version + 1])->save();
        $this->audit->record("compliance.payment_{$status}", $advice, new: ['from' => $delivery->fromName, 'amount_minor' => $advice->amount_minor], reason: $reason,
            organizationId: $advice->organization_id);

        $factory = Organization::query()->findOrFail($advice->organization_id);
        $company = $this->place->companyOf($factory);
        $sender = User::query()->whereKey($advice->sent_by)->get();
        if ($sender->isNotEmpty()) {
            $this->notifier->notify('compliance.advice_answered', $sender, fn (string $locale) => [
                'organization' => $factory->displayName($locale),
                'other' => $delivery->fromName,
                'amount' => MoneyText::format($advice->amount_minor, $advice->currency_code, $locale),
                'answer' => __("compliance::compliance.advice_answers.{$status}", [], $locale),
            ], $company->partner, $company);
        }

        return $advice->getKey();
    }
}
