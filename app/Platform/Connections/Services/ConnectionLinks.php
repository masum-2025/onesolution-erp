<?php

namespace App\Platform\Connections\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionUnit;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Making and running a business connection (NET-1). The provider (a company)
 * makes an invitation code, shown once; the client (a group or a company)
 * looks it up, sees who invites it, and accepts it choosing which of its
 * companies and branches the provider may deal with. Either side pauses
 * (only that side resumes) or ends it with a reason; ending is final. Only
 * the client changes the units. Every step is audited on both sides.
 */
class ConnectionLinks
{
    /** Easy to read aloud and type: no 0/O, 1/I/L. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 12;

    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
        private Notifier $notifier,
        private Recipients $recipients,
    ) {}

    /**
     * @return array{connection: Connection, code: string}
     */
    public function invite(Organization $provider, ?string $note, User $actor): array
    {
        if ($provider->type !== OrganizationType::Company) {
            throw ConnectionException::providerMustBeCompany();
        }
        $code = $this->newCode();
        $days = (int) $this->rules->get('business_network.invite_valid_days', $this->contexts->forOrganization($provider));

        $connection = new Connection;
        $connection->forceFill([
            'provider_id' => $provider->getKey(),
            'status' => Connection::INVITED,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
            'code_hash' => self::hash($code),
            'expires_at' => CarbonImmutable::now()->addDays($days),
            'invited_by' => $actor->getKey(),
            'version' => 1,
        ])->save();

        $this->audit->record('business_network.invited', $connection, new: ['note' => $connection->note, 'expires_at' => $connection->expires_at->toIso8601String()],
            actor: $actor, organizationId: $provider->getKey(), partnerId: $provider->partner_id);

        return ['connection' => $connection, 'code' => self::display($code)];
    }

    /** An invitation still open, found by its code (any spacing, dashes or case). */
    public function byCode(string $code): Connection
    {
        $normal = self::normal($code);
        $connection = strlen($normal) === self::CODE_LENGTH ? Connection::query()->with('provider')->where('code_hash', self::hash($normal))->first() : null;
        if ($connection === null) {
            throw ConnectionException::codeNotFound();
        }
        if ($connection->status !== Connection::INVITED || $connection->expires_at === null || $connection->expires_at->isPast()) {
            throw ConnectionException::codeClosed();
        }

        return $connection;
    }

    /**
     * @param  list<string>  $unitIds  The client's companies and branches the provider may deal with.
     */
    public function accept(string $code, Organization $client, array $unitIds, User $actor): Connection
    {
        if (! in_array($client->type, [OrganizationType::Group, OrganizationType::Company], true)) {
            throw ConnectionException::clientMustBeGroupOrCompany();
        }
        $units = $this->ownUnits($client, $unitIds);

        return DB::transaction(function () use ($code, $client, $units, $actor) {
            $connection = $this->byCode($code);
            $connection = Connection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
            if ($connection->status !== Connection::INVITED) {
                throw ConnectionException::codeClosed();
            }
            /** @var Organization $provider */
            $provider = Organization::query()->findOrFail($connection->provider_id);
            // A client never connects to itself, nor to anything in its own tree.
            if ($provider->root_id === $client->root_id) {
                throw ConnectionException::ownOrganization();
            }
            if ($provider->partner_id !== $client->partner_id && ! ($this->allowsOtherPartners($provider) && $this->allowsOtherPartners($client))) {
                throw ConnectionException::otherPartner();
            }
            $open = Connection::query()->where('provider_id', $provider->getKey())->where('client_id', $client->getKey())
                ->whereIn('status', [Connection::ACTIVE, Connection::PAUSED])->lockForUpdate()->exists();
            if ($open) {
                throw ConnectionException::alreadyConnected();
            }

            $connection->forceFill([
                'client_id' => $client->getKey(),
                'status' => Connection::ACTIVE,
                // Used: the code can never be looked up again.
                'code_hash' => null,
                'accepted_at' => CarbonImmutable::now(),
                'accepted_by' => $actor->getKey(),
                'version' => $connection->version + 1,
            ])->save();
            foreach ($units as $unit) {
                $this->addUnit($connection, $unit, $actor);
            }

            $new = ['provider' => $provider->getKey(), 'client' => $client->getKey(), 'units' => $units->modelKeys()];
            $this->audit->record('business_network.accepted', $connection, new: $new, actor: $actor, organizationId: $client->getKey(), partnerId: $client->partner_id);
            $this->audit->record('business_network.accepted', $connection, new: $new, actor: $actor, organizationId: $provider->getKey(), partnerId: $provider->partner_id);
            $this->tell('business_network.connected', $provider, $client, $connection);

            return $connection;
        });
    }

    /**
     * The client changes which of its units the provider may deal with.
     *
     * @param  list<string>  $unitIds
     */
    public function setUnits(Connection $connection, Organization $client, array $unitIds, int $baseVersion, User $actor): Connection
    {
        if ($connection->sideOf($client->getKey()) !== Connection::CLIENT) {
            throw ConnectionException::notYourSide();
        }
        $units = $this->ownUnits($client, $unitIds);

        return DB::transaction(function () use ($connection, $client, $units, $baseVersion, $actor) {
            $connection = $this->locked($connection, $baseVersion, [Connection::ACTIVE, Connection::PAUSED]);
            $current = $this->activeUnits($connection)->keyBy('unit_id');
            $wanted = $units->keyBy(fn (Organization $unit) => $unit->getKey());

            $removed = $current->keys()->diff($wanted->keys())->values();
            $added = $wanted->keys()->diff($current->keys())->values();
            ConnectionUnit::query()->where('connection_id', $connection->getKey())->whereNull('removed_at')->whereIn('unit_id', $removed->all())
                ->update(['removed_at' => now(), 'removed_by' => $actor->getKey()]);
            foreach ($added as $id) {
                $this->addUnit($connection, $wanted[$id], $actor);
            }
            $connection->forceFill(['version' => $connection->version + 1])->save();

            $this->audit->record('business_network.units_changed', $connection, old: ['units' => $current->keys()->values()->all()], new: ['units' => $wanted->keys()->values()->all()],
                actor: $actor, organizationId: $client->getKey(), partnerId: $client->partner_id);
            $this->audit->record('business_network.units_changed', $connection, old: ['units' => $current->keys()->values()->all()], new: ['units' => $wanted->keys()->values()->all()],
                actor: $actor, organizationId: $connection->provider_id, partnerId: Organization::query()->whereKey($connection->provider_id)->value('partner_id'));

            return $connection;
        });
    }

    /** pause | resume | end, by the side in the address. */
    public function step(Connection $connection, Organization $side, string $step, int $baseVersion, ?string $reason, User $actor): Connection
    {
        $mine = $connection->sideOf($side->getKey()) ?? throw ConnectionException::notYourSide();

        return DB::transaction(function () use ($connection, $side, $step, $baseVersion, $reason, $actor, $mine) {
            $connection = match ($step) {
                'pause' => $this->locked($connection, $baseVersion, [Connection::ACTIVE]),
                'resume' => $this->locked($connection, $baseVersion, [Connection::PAUSED]),
                default => $this->locked($connection, $baseVersion, [Connection::INVITED, Connection::ACTIVE, Connection::PAUSED]),
            };
            // Only the side that paused resumes; an invitation is ended (withdrawn) by the provider only.
            if ($step === 'resume' && $connection->paused_by_side !== $mine) {
                throw ConnectionException::notYourSide();
            }
            $old = ['status' => $connection->status];
            $connection->forceFill(match ($step) {
                'pause' => ['status' => Connection::PAUSED, 'paused_by_side' => $mine, 'paused_at' => CarbonImmutable::now()],
                'resume' => ['status' => Connection::ACTIVE, 'paused_by_side' => null, 'paused_at' => null],
                default => ['status' => Connection::ENDED, 'code_hash' => null, 'ended_by_side' => $mine, 'ended_by' => $actor->getKey(), 'ended_at' => CarbonImmutable::now(), 'end_reason' => $reason],
            })->forceFill(['version' => $connection->version + 1])->save();

            $action = 'business_network.'.['pause' => 'paused', 'resume' => 'resumed', 'end' => 'ended'][$step];
            foreach (array_filter([$connection->provider_id, $connection->client_id]) as $organizationId) {
                $this->audit->record($action, $connection, old: $old, new: ['status' => $connection->status, 'by' => $mine], reason: $reason,
                    actor: $actor, organizationId: $organizationId, partnerId: Organization::query()->whereKey($organizationId)->value('partner_id'));
            }
            // The other side hears of a pause or an end of a working connection.
            if ($step !== 'resume' && $connection->client_id !== null) {
                $other = Organization::query()->findOrFail($mine === Connection::PROVIDER ? $connection->client_id : $connection->provider_id);
                $this->tell($step === 'pause' ? 'business_network.paused' : 'business_network.ended', $other, $side, $connection, (string) $reason);
            }

            return $connection;
        });
    }

    /**
     * Connections an organization is a side of, itself or through a unit above it.
     *
     * @return Collection<int, Connection>
     */
    public function visibleTo(Organization $organization): Collection
    {
        $chain = [...$organization->ancestorIds(), $organization->getKey()];

        return Connection::query()->with(['provider', 'client'])
            ->where(fn ($query) => $query->whereIn('provider_id', $chain)->orWhereIn('client_id', $chain))
            ->orderByDesc('created_at')->limit(500)->get();
    }

    /**
     * @return Collection<int, ConnectionUnit>
     */
    public function activeUnits(Connection $connection): Collection
    {
        return ConnectionUnit::query()->with('unit')->where('connection_id', $connection->getKey())->whereNull('removed_at')->get();
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** ABCD23EFGH45 -> ABCD-23EF-GH45 */
    public static function display(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private static function normal(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * The client's own open companies and branches (itself included), at least one.
     *
     * @param  list<string>  $unitIds
     * @return Collection<int, Organization>
     */
    private function ownUnits(Organization $client, array $unitIds): Collection
    {
        $unitIds = array_values(array_unique($unitIds));
        if ($unitIds === []) {
            throw ConnectionException::unitsNeeded();
        }
        $units = Organization::query()->subtreeOf($client)->whereKey($unitIds)
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Branch->value])
            ->where('status', OrganizationStatus::Active->value)->get();
        if ($units->count() !== count($unitIds)) {
            throw ConnectionException::unitNotYours();
        }

        return $units;
    }

    private function addUnit(Connection $connection, Organization $unit, User $actor): void
    {
        (new ConnectionUnit)->forceFill([
            'connection_id' => $connection->getKey(),
            'unit_id' => $unit->getKey(),
            'added_by' => $actor->getKey(),
        ])->save();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function locked(Connection $connection, int $baseVersion, array $statuses): Connection
    {
        /** @var Connection $fresh */
        $fresh = Connection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ConnectionException::versionConflict();
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw ConnectionException::wrongStatus($fresh->status);
        }

        return $fresh;
    }

    private function allowsOtherPartners(Organization $organization): bool
    {
        return (bool) $this->rules->get('business_network.allow_other_partners', $this->contexts->forOrganization($organization));
    }

    /** Names only: who, which connection; never what the two exchange. */
    private function tell(string $key, Organization $to, Organization $other, Connection $connection, string $reason = ''): void
    {
        $people = $this->recipients->holding('business_network.manage', $to);
        if ($people->isEmpty()) {
            return;
        }
        $this->notifier->notify($key, $people->values(), fn (string $locale) => [
            'organization' => $to->displayName($locale),
            'other' => $other->displayName($locale),
            'reason' => $reason,
        ], $to->partner, $to);
    }

    private function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }
}
