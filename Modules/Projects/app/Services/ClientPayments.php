<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Services\Connections;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Accounting\Services\Customers;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\PaymentAdvice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectLink;

/**
 * Payments a connected client says it made against a project's invoice
 * (compliance.payment_advice, optional: only over a business connection).
 * The advice never posts money by itself: someone holding projects.bill and
 * accounting.sell checks it arrived and records the receipt in Accounting
 * (approval rules apply; only an approved receipt lowers what is owed), or
 * answers that it did not arrive. Either answer goes back to the client
 * (projects.advice_reply). Each advice is decided once.
 */
class ClientPayments
{
    public const KIND = 'compliance.payment_advice';

    public const REPLY = 'projects.advice_reply';

    public function __construct(
        private Workplace $place,
        private Customers $customers,
        private Connections $connections,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, PaymentAdvice>
     */
    public function forProject(Project $project): Collection
    {
        $unit = Organization::query()->findOrFail($project->organization_id);

        return $this->place->query(PaymentAdvice::class, $unit, [$unit->getKey()])->where('project_id', $project->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function item(PaymentAdvice $advice): array
    {
        return [
            'id' => $advice->getKey(),
            'from' => $advice->from_name,
            'by' => $advice->by_name,
            'invoice_id' => $advice->invoice_id,
            'invoice_number' => $advice->invoice_number,
            'amount_minor' => $advice->amount_minor,
            'currency_code' => $advice->currency_code,
            'paid_on' => $advice->paid_on->toDateString(),
            'method' => $advice->method,
            'reference' => $advice->reference,
            'note' => $advice->note,
            'status' => $advice->status,
            'settlement_id' => $advice->settlement_id,
            'reason' => $advice->reason,
            'decided_at' => $advice->decided_at?->toIso8601String(),
            'created_at' => $advice->created_at?->toIso8601String(),
            'version' => $advice->version,
        ];
    }

    public function find(Project $project, string $id): PaymentAdvice
    {
        return $this->forProject($project)->firstWhere('id', $id) ?? throw ProjectsException::adviceNotFound();
    }

    /** Where the receipt can go in: the company's cash, bank and wallet accounts. */
    public function moneyAccounts(Project $project): array
    {
        return $this->customers->moneyAccounts(Organization::query()->findOrFail($project->company_id));
    }

    /** compliance.payment_advice: kept once, only for an invoice this very connection carried. */
    public function receive(ConnectionDelivery $delivery): ?string
    {
        $payload = $delivery->payload;
        $adviceId = (string) ($payload['advice_id'] ?? '');
        $amount = $payload['amount_minor'] ?? null;
        $paidOn = (string) ($payload['paid_on'] ?? '');
        if ($adviceId === '' || ! is_int($amount) || $amount <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) {
            return null;
        }
        $existing = PaymentAdvice::query()->withoutGlobalScope(OrganizationScope::class)->where('connection_id', $delivery->connectionId)
            ->where('client_advice_id', $adviceId)->first();
        if ($existing !== null) {
            return $existing->getKey();
        }
        $link = ProjectLink::query()->withoutGlobalScope(OrganizationScope::class)->where('kind', ProjectLink::INVOICE)
            ->where('target_id', (string) ($payload['invoice_id'] ?? ''))->whereNotNull('sent_at')->first();
        $project = $link === null ? null : Project::query()->withoutGlobalScope(OrganizationScope::class)->find($link->project_id);
        if ($link === null || $project === null || $project->connection_id !== $delivery->connectionId) {
            return null;
        }
        $text = fn (mixed $value, int $max) => ($clean = mb_substr(trim((string) $value), 0, $max)) === '' ? null : $clean;

        $advice = PaymentAdvice::query()->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->getKey(),
            'connection_id' => $delivery->connectionId,
            'client_advice_id' => $adviceId,
            'client_unit_id' => $delivery->fromUnitId,
            'message_id' => $delivery->messageId,
            'from_name' => mb_substr($delivery->fromName, 0, 190),
            'by_name' => $text($payload['by'] ?? null, 190),
            'invoice_id' => $link->target_id,
            'invoice_number' => $text($payload['number'] ?? null, 60),
            'amount_minor' => $amount,
            'currency_code' => preg_match('/^[A-Z]{3}$/', (string) ($payload['currency_code'] ?? '')) ? $payload['currency_code'] : 'XXX',
            'paid_on' => $paidOn,
            'method' => in_array($payload['method'] ?? null, PaymentAdvice::METHODS, true) ? $payload['method'] : 'other',
            'reference' => $text($payload['reference'] ?? null, 100),
            'note' => $text($payload['note'] ?? null, 500),
            'status' => PaymentAdvice::NEW,
            'version' => 1,
        ]);
        $this->audit->record('projects.payment_advice_received', $project, new: ['from' => $delivery->fromName, 'amount_minor' => $amount, 'invoice' => $advice->invoice_number],
            organizationId: $project->organization_id);

        $company = Organization::query()->findOrFail($project->company_id);
        $people = User::query()->whereKey(array_values(array_filter(array_unique([$link->sent_by, $project->manager_id]))))->get();
        if ($people->isNotEmpty()) {
            $this->notifier->notify('projects.payment_advised', $people, fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'project' => "{$project->code} {$project->name}",
                'other' => $delivery->fromName,
                'amount' => MoneyText::format($amount, $advice->currency_code, $locale),
            ], $company->partner, $company);
        }

        return $advice->getKey();
    }

    /**
     * The money arrived: a receipt against the invoice in Accounting.
     *
     * @param  array{account_id: string, settled_on: string, base_version: int}  $data
     */
    public function record(Project $project, PaymentAdvice $advice, array $data, User $actor): PaymentAdvice
    {
        $company = Organization::query()->findOrFail($project->company_id);
        if (! $this->customers->available($company)) {
            throw ProjectsException::booksOff();
        }
        $invoice = $this->customers->invoices($company, [$advice->invoice_id])[$advice->invoice_id] ?? throw ProjectsException::invoiceNotOfProject();
        if ($invoice['currency_code'] !== $advice->currency_code) {
            throw ProjectsException::adviceCurrency();
        }

        return $this->decide($project, $advice, (int) $data['base_version'], $actor, function (PaymentAdvice $fresh) use ($company, $data, $actor) {
            $receipt = $this->customers->receiveFor($company, $fresh->invoice_id, [
                'account_id' => $data['account_id'],
                'amount_minor' => $fresh->amount_minor,
                'settled_on' => $data['settled_on'],
                'reference' => $fresh->reference,
                'memo' => mb_substr(__('projects::projects.advice_memo', ['from' => $fresh->from_name, 'number' => (string) $fresh->invoice_number]), 0, 255),
                'op_id' => 'advice-'.$fresh->getKey(),
            ], $actor);

            return ['status' => PaymentAdvice::RECORDED, 'settlement_id' => $receipt['id'], 'reason' => null];
        });
    }

    /** Nothing arrived (or not this amount): the client is told why. */
    public function notReceived(Project $project, PaymentAdvice $advice, int $baseVersion, string $reason, User $actor): PaymentAdvice
    {
        return $this->decide($project, $advice, $baseVersion, $actor, fn () => ['status' => PaymentAdvice::NOT_RECEIVED, 'settlement_id' => null, 'reason' => $reason]);
    }

    /**
     * @param  callable(PaymentAdvice): array{status: string, settlement_id: string|null, reason: string|null}  $outcome
     */
    private function decide(Project $project, PaymentAdvice $advice, int $baseVersion, User $actor, callable $outcome): PaymentAdvice
    {
        $connection = $this->connections->find($advice->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ProjectsException::connectionClosed();
        }
        $unit = Organization::query()->findOrFail($project->organization_id);

        return $this->place->transaction($unit, function () use ($project, $advice, $baseVersion, $actor, $outcome, $connection, $unit) {
            /** @var PaymentAdvice $fresh */
            $fresh = PaymentAdvice::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)->whereKey($advice->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->status !== PaymentAdvice::NEW) {
                throw ProjectsException::adviceDecided();
            }
            if ($fresh->version !== $baseVersion) {
                throw ProjectsException::versionConflict();
            }
            $result = $outcome($fresh);
            $fresh->forceFill([...$result, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'version' => $fresh->version + 1])->save();
            $this->connections->send($connection, self::REPLY, $unit, $fresh->client_unit_id,
                ['advice_id' => $fresh->client_advice_id, 'status' => $result['status'], 'reason' => $result['reason'], 'by' => $actor->name],
                [], 'advice-reply-'.$fresh->getKey(), $actor);
            $this->audit->record("projects.payment_{$result['status']}", $project, new: ['amount_minor' => $fresh->amount_minor, 'invoice' => $fresh->invoice_number, 'receipt' => $result['settlement_id']],
                reason: $result['reason'], actor: $actor, organizationId: $unit->getKey());

            return $fresh;
        });
    }
}
