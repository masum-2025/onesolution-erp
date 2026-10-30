<?php

namespace App\Platform\Connections\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionKinds;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Jobs\DeliverConnectionMessage;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Connections\Models\ConnectionUnit;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The public service other modules use to work with the other side of a
 * business connection (NET-1):
 *
 *     $connections = app(Connections::class);
 *     foreach ($connections->clientsOf($provider) as $connection) { ... }     // a provider's clients
 *     $connections->covers($connection, $factoryId);                         // may the provider deal with it?
 *     $connections->send($connection, 'compliance.renewal_request', $factory, $provider->id,
 *         ['items' => [...]], files: [], opId: 'request-01J…', actor: $user);  // queued, delivered into the other side's data
 *
 * A module never reads the other side's tables: what it needs travels in a
 * message, or is a unit name read here. Nothing is sent while the connection
 * is not active, to a unit it does not cover, or of a kind whose module is off.
 */
class Connections
{
    /** A message is data for a record, not a file: kept small. */
    public const MAX_PAYLOAD_BYTES = 65536;

    public function __construct(
        private ConnectionKinds $kinds,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * Active connections where the organization (or a unit above it) is the provider.
     *
     * @return Collection<int, Connection>
     */
    public function clientsOf(Organization $organization): Collection
    {
        return Connection::query()->with('client')->where('status', Connection::ACTIVE)
            ->whereIn('provider_id', [...$organization->ancestorIds(), $organization->getKey()])->get();
    }

    /**
     * Active connections whose client is the organization or above it, and that cover it.
     *
     * @return Collection<int, Connection>
     */
    public function providersOf(Organization $organization): Collection
    {
        return Connection::query()->with('provider')->where('status', Connection::ACTIVE)
            ->whereIn('client_id', [...$organization->ancestorIds(), $organization->getKey()])->get()
            ->filter(fn (Connection $connection) => $this->covers($connection, $organization->getKey()))->values();
    }

    /** Whether the provider may deal with this unit of the client: it, or a unit above it, is shared. */
    public function covers(Connection $connection, string $unitId): bool
    {
        $unit = Organization::query()->find($unitId);
        if ($unit === null || $connection->client_id === null) {
            return false;
        }

        return ConnectionUnit::query()->where('connection_id', $connection->getKey())->whereNull('removed_at')
            ->whereIn('unit_id', [...$unit->ancestorIds(), $unit->getKey()])->exists();
    }

    /**
     * The client's units the provider may deal with: each shared unit and the
     * companies and branches below it, by name only.
     *
     * @return list<array{id: string, type: string, name: string, parent_id: string|null}>
     */
    public function unitsFor(Connection $connection, ?string $locale = null): array
    {
        $shared = ConnectionUnit::query()->with('unit')->where('connection_id', $connection->getKey())->whereNull('removed_at')->get()
            ->pluck('unit')->filter();
        $units = collect();
        foreach ($shared as $unit) {
            $units = $units->merge(Organization::query()->subtreeOf($unit)->whereIn('type', ['company', 'branch'])->get());
        }

        return $units->unique(fn (Organization $unit) => $unit->getKey())->sortBy('path')->map(fn (Organization $unit) => [
            'id' => $unit->getKey(),
            'type' => $unit->type->value,
            'name' => $unit->displayName($locale),
            'parent_id' => $unit->parent_id,
        ])->values()->all();
    }

    public function find(string $connectionId): ?Connection
    {
        return Connection::query()->find($connectionId);
    }

    /**
     * Queue a message to the other side; delivered into its own data after this transaction commits.
     *
     * @param  Organization  $from  The sending unit: the provider company, or a covered unit of the client.
     * @param  string  $toUnitId  The receiving unit: a covered unit of the client, or the provider company.
     * @param  array<string, mixed>  $payload
     * @param  list<array{path: string, name: string, mime: string}>  $files  On the sender's "local" (private) disk.
     */
    public function send(Connection $connection, string $kind, Organization $from, string $toUnitId, array $payload, array $files, string $opId, ?User $actor): ConnectionMessage
    {
        if (! $this->kinds->has($kind)) {
            throw ConnectionException::kindNotAvailable($kind);
        }
        $direction = $this->kinds->handler($kind)->direction();
        $this->assertSides($connection, $direction, $from, $toUnitId);
        // The kind is named after the module that sends it (compliance.renewal_request); the
        // receiving module (the handler's) is checked at the other side when it is delivered.
        $sender = strstr($kind, '.', true);
        if (! $this->modules->isEnabled('business_network', $from) || ! $this->modules->isEnabled($sender, $from)) {
            throw ConnectionException::kindNotAvailable($kind);
        }
        if (strlen((string) json_encode($payload)) > self::MAX_PAYLOAD_BYTES) {
            throw ConnectionException::payloadTooLarge();
        }
        $files = $this->checkedFiles($files, $from);

        $existing = ConnectionMessage::query()->where('connection_id', $connection->getKey())->where('op_id', $opId)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($connection, $kind, $direction, $from, $toUnitId, $payload, $files, $opId, $actor) {
                $message = new ConnectionMessage;
                $message->forceFill([
                    'connection_id' => $connection->getKey(),
                    'direction' => $direction,
                    'kind' => $kind,
                    'from_unit_id' => $from->getKey(),
                    'to_unit_id' => $toUnitId,
                    'sent_by' => $actor?->getKey(),
                    'op_id' => $opId,
                    'payload' => $payload,
                    'files' => $files,
                    'status' => ConnectionMessage::QUEUED,
                ])->save();

                // The envelope only: never the payload.
                $this->audit->record('business_network.message_sent', $message, new: ['kind' => $kind, 'to' => $toUnitId, 'files' => count($files)],
                    actor: $actor, organizationId: $from->getKey(), partnerId: $from->partner_id);
                DeliverConnectionMessage::dispatch($message->getKey())->afterCommit();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // The same op_id sent twice at once: the first one stands.
            return ConnectionMessage::query()->where('connection_id', $connection->getKey())->where('op_id', $opId)->firstOrFail();
        }
    }

    private function assertSides(Connection $connection, string $direction, Organization $from, string $toUnitId): void
    {
        if (! $connection->isActive()) {
            throw ConnectionException::notActive();
        }
        if ($direction === ConnectionMessage::TO_CLIENT) {
            // The provider company itself (or a unit of it) sends, to a unit the connection covers.
            $fromProvider = $from->getKey() === $connection->provider_id || in_array($connection->provider_id, $from->ancestorIds(), true);
            if (! $fromProvider) {
                throw ConnectionException::notYourSide();
            }
            if (! $this->covers($connection, $toUnitId)) {
                throw ConnectionException::unitNotCovered();
            }

            return;
        }
        if (! $this->covers($connection, $from->getKey())) {
            throw ConnectionException::unitNotCovered();
        }
        if ($toUnitId !== $connection->provider_id) {
            throw ConnectionException::notYourSide();
        }
    }

    /**
     * Each file exists on the sender's private disk and fits the size rule; its checksum taken now.
     *
     * @param  list<array{path: string, name: string, mime: string}>  $files
     * @return list<array{path: string, name: string, mime: string, size: int, sha256: string}>
     */
    private function checkedFiles(array $files, Organization $from): array
    {
        $mb = (int) $this->rules->get('business_network.max_file_mb', $this->contexts->forOrganization($from));
        $disk = Storage::disk('local');
        $checked = [];
        foreach ($files as $file) {
            if (! is_string($file['path'] ?? null) || ! $disk->exists($file['path'])) {
                throw ConnectionException::fileMissing();
            }
            $size = $disk->size($file['path']);
            if ($size > $mb * 1024 * 1024) {
                throw ConnectionException::fileTooLarge($mb);
            }
            $checked[] = [
                'path' => $file['path'],
                'name' => mb_substr((string) ($file['name'] ?? basename($file['path'])), 0, 200),
                'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
                'size' => $size,
                'sha256' => hash_file('sha256', $disk->path($file['path'])),
            ];
        }

        return $checked;
    }
}
