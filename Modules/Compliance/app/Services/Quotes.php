<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Services\Connections;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\ReceivedQuote;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Support\Validity;

/**
 * Quotations a connected provider sends (projects.quotation, optional:
 * only over a business connection): kept as they came, line by line, until
 * someone of the client holding compliance.quotes accepts or declines them;
 * the answer goes back (compliance.quote_reply) and the provider's CRM takes
 * it. A newer version of the same quotation replaces a pending one.
 */
class Quotes
{
    public const KIND = 'projects.quotation';

    public const REPLY = 'compliance.quote_reply';

    public function __construct(
        private Place $place,
        private Connections $connections,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, ReceivedQuote>
     */
    public function list(Organization $unit, bool $pendingOnly = false): Collection
    {
        return $this->place->query(ReceivedQuote::class, $unit, $this->place->subtreeIds($unit))
            ->when($pendingOnly, fn ($query) => $query->where('status', ReceivedQuote::PENDING))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get();
    }

    /**
     * Quotations for one renewal request (newest first).
     *
     * @return Collection<int, ReceivedQuote>
     */
    public function forRequest(Organization $unit, string $requestId): Collection
    {
        return $this->place->query(ReceivedQuote::class, $unit, $this->place->subtreeIds($unit))->where('request_id', $requestId)
            ->orderByDesc('created_at')->get();
    }

    public function find(Organization $unit, string $id): ReceivedQuote
    {
        return $this->place->query(ReceivedQuote::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ComplianceException::quoteNotFound();
    }

    /** projects.quotation arrived for a shared factory; once per message. */
    public function receive(ConnectionDelivery $delivery): ?string
    {
        $existing = ReceivedQuote::query()->withoutGlobalScope(OrganizationScope::class)->where('message_id', $delivery->messageId)->first();
        if ($existing !== null) {
            return $existing->getKey();
        }
        $payload = $delivery->payload;
        $quoteId = (string) ($payload['quote_id'] ?? '');
        if ($quoteId === '' || ! is_array($payload['lines'] ?? null)) {
            return null;
        }
        $factory = $delivery->to;
        $request = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $factory->getKey())
            ->where('connection_id', $delivery->connectionId)->whereKey((string) ($payload['request_id'] ?? ''))->first();
        $int = fn (mixed $value) => is_int($value) && $value >= 0 ? $value : 0;
        $text = fn (mixed $value, int $max) => ($clean = mb_substr(trim((string) $value), 0, $max)) === '' ? null : $clean;
        $lines = array_values(array_map(fn ($line) => [
            'description' => mb_substr((string) ($line['description'] ?? ''), 0, 255),
            'quantity_milli' => $int($line['quantity_milli'] ?? 0),
            'unit' => $text($line['unit'] ?? null, 20),
            'unit_price_minor' => $int($line['unit_price_minor'] ?? 0),
            'discount_minor' => $int($line['discount_minor'] ?? 0),
            'tax_rate_bp' => $int($line['tax_rate_bp'] ?? 0),
            'net_minor' => $int($line['net_minor'] ?? 0),
            'tax_minor' => $int($line['tax_minor'] ?? 0),
            'total_minor' => $int($line['total_minor'] ?? 0),
        ], array_slice(array_filter($payload['lines'], 'is_array'), 0, 200)));

        // A newer version of the same quotation: the pending one is replaced.
        ReceivedQuote::query()->withoutGlobalScope(OrganizationScope::class)->where('connection_id', $delivery->connectionId)
            ->where('provider_quote_id', $quoteId)->where('status', ReceivedQuote::PENDING)
            ->update(['status' => ReceivedQuote::REPLACED, 'updated_at' => now()]);

        $quote = ReceivedQuote::query()->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $factory->getKey(),
            'connection_id' => $delivery->connectionId,
            'message_id' => $delivery->messageId,
            'provider_quote_id' => $quoteId,
            'request_id' => $request?->getKey(),
            'from_name' => mb_substr($delivery->fromName, 0, 190),
            'number' => $text($payload['number'] ?? null, 60),
            'subject' => $text($payload['subject'] ?? null, 190),
            'issue_date' => Validity::day($payload['issue_date'] ?? null),
            'valid_until' => Validity::day($payload['valid_until'] ?? null),
            'currency_code' => preg_match('/^[A-Z]{3}$/', (string) ($payload['currency_code'] ?? '')) ? $payload['currency_code'] : 'XXX',
            'prices_include_tax' => (bool) ($payload['prices_include_tax'] ?? false),
            'subtotal_minor' => $int($payload['subtotal_minor'] ?? 0),
            'discount_minor' => $int($payload['discount_minor'] ?? 0),
            'tax_minor' => $int($payload['tax_minor'] ?? 0),
            'total_minor' => $int($payload['total_minor'] ?? 0),
            'lines' => $lines,
            'notes' => $text($payload['notes'] ?? null, 5000),
            'terms' => $text($payload['terms'] ?? null, 5000),
            'status' => ReceivedQuote::PENDING,
            'version' => 1,
        ]);
        $this->audit->record('compliance.quote_received', $quote, new: ['from' => $delivery->fromName, 'number' => $quote->number, 'total_minor' => $quote->total_minor],
            organizationId: $factory->getKey());

        $company = $this->place->companyOf($factory);
        $this->notifier->notify('compliance.quote_received', $this->recipients->holding('compliance.quotes', $factory), fn (string $locale) => [
            'organization' => $factory->displayName($locale),
            'other' => $delivery->fromName,
            'number' => (string) $quote->number,
        ], $company->partner, $company);

        return $quote->getKey();
    }

    /** accept or decline (with a reason); the provider is told at once. */
    public function decide(Organization $unit, ReceivedQuote $quote, string $step, int $baseVersion, ?string $reason, User $actor): ReceivedQuote
    {
        $connection = $this->connections->find($quote->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ConnectionException::notActive();
        }
        $factory = Organization::query()->findOrFail($quote->organization_id);

        return $this->place->transaction($unit, function () use ($quote, $step, $baseVersion, $reason, $actor, $connection, $factory) {
            /** @var ReceivedQuote $fresh */
            $fresh = ReceivedQuote::inTenantOf($quote->organization_id)->withoutGlobalScope(OrganizationScope::class)->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->status !== ReceivedQuote::PENDING) {
                throw ComplianceException::quoteDecided();
            }
            if ($fresh->version !== $baseVersion) {
                throw ComplianceException::versionConflict();
            }
            $status = $step === 'accept' ? ReceivedQuote::ACCEPTED : ReceivedQuote::DECLINED;
            $fresh->forceFill(['status' => $status, 'reason' => $reason, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'version' => $fresh->version + 1])->save();
            $this->connections->send($connection, self::REPLY, $factory, $connection->provider_id,
                ['quote_id' => $fresh->provider_quote_id, 'status' => $status, 'reason' => $reason, 'by' => $actor->name],
                [], 'quote-reply-'.$fresh->getKey(), $actor);
            $this->audit->record("compliance.quote_{$status}", $fresh, new: ['number' => $fresh->number, 'total_minor' => $fresh->total_minor], reason: $reason,
                actor: $actor, organizationId: $fresh->organization_id);

            return $fresh;
        });
    }
}
