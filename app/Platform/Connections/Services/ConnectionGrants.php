<?php

namespace App\Platform\Connections\Services;

use App\Models\User;
use App\Platform\Access\PermissionCatalog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionGrant;
use App\Platform\Modules\ModuleDefinition;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a client lets a connected provider do in its own data (NET-2), module
 * by module: the module's own permissions only (never platform ones such as
 * members, roles, rules or billing), optionally until a day. Only the client
 * gives or takes them back; both sides see them and both audit logs keep
 * every change. Working with them happens in a connection context
 * (ContextResolver::enterConnection), checked again on every request.
 */
class ConnectionGrants
{
    /** Modules never given: they hold the connections themselves or the platform's own settings. */
    private const NEVER = ['business_network', 'client_portal', 'online_payments', 'api_integration', 'external_integrations', 'advanced_audit'];

    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $modules,
        private PermissionCatalog $permissions,
        private AuditLogger $audit,
    ) {}

    /**
     * Modules the client could give at its organization: on there, with permissions of their own.
     *
     * @return list<array{module: string, name: string, permissions: list<array{key: string, label: string}>}>
     */
    public function grantable(Organization $client): array
    {
        return collect($this->registry->all())
            ->filter(fn (ModuleDefinition $module) => $this->isGrantable($module) && $this->modules->isEnabled($module->key, $client))
            ->map(fn (ModuleDefinition $module) => [
                'module' => $module->key,
                'name' => $module->label(),
                'permissions' => array_map(fn (string $key) => ['key' => $key, 'label' => $this->permissions->has($key) ? $this->permissions->get($key)->label() : $key], $module->permissions),
            ])
            ->sortBy('name')->values()->all();
    }

    /**
     * @return Collection<int, ConnectionGrant>
     */
    public function of(Connection $connection): Collection
    {
        return ConnectionGrant::query()->where('connection_id', $connection->getKey())->orderBy('module')->get();
    }

    /**
     * The permissions in force now at a unit of the client: every active grant
     * whose module is on there.
     *
     * @return array<string, list<string>> module => permissions
     */
    public function inForce(Connection $connection, Organization $unit): array
    {
        if (! $connection->isActive()) {
            return [];
        }

        return $this->of($connection)
            ->filter(fn (ConnectionGrant $grant) => $grant->isActive() && $this->registry->has($grant->module) && $this->modules->isEnabled($grant->module, $unit))
            ->mapWithKeys(fn (ConnectionGrant $grant) => [$grant->module => array_values(array_intersect((array) $grant->permissions, $this->registry->get($grant->module)->permissions))])
            ->filter()
            ->all();
    }

    /**
     * Give (or change) what the provider may do in one module.
     *
     * @param  list<string>  $permissions
     */
    public function give(Connection $connection, Organization $client, string $module, array $permissions, ?string $expiresOn, User $actor): ConnectionGrant
    {
        if ($connection->sideOf($client->getKey()) !== Connection::CLIENT) {
            throw ConnectionException::notYourSide();
        }
        if (! in_array($connection->status, [Connection::ACTIVE, Connection::PAUSED], true)) {
            throw ConnectionException::wrongStatus($connection->status);
        }
        $definition = $this->registry->has($module) ? $this->registry->get($module) : null;
        if ($definition === null || ! $this->isGrantable($definition) || ! $this->modules->isEnabled($module, $client)) {
            throw ConnectionException::moduleNotGrantable();
        }
        $permissions = array_values(array_unique($permissions));
        if ($permissions === [] || array_diff($permissions, $definition->permissions) !== []) {
            throw ConnectionException::permissionsNotOfModule();
        }
        $until = $expiresOn === null ? null : CarbonImmutable::parse($expiresOn, 'UTC')->endOfDay();

        return DB::transaction(function () use ($connection, $client, $module, $permissions, $until, $actor) {
            $grant = ConnectionGrant::query()->where('connection_id', $connection->getKey())->where('module', $module)->lockForUpdate()->first()
                ?? (new ConnectionGrant)->forceFill(['connection_id' => $connection->getKey(), 'module' => $module, 'version' => 0]);
            $old = $grant->exists ? $this->values($grant) : [];
            $grant->forceFill([
                'permissions' => $permissions,
                'expires_at' => $until,
                'granted_by' => $actor->getKey(),
                'revoked_at' => null,
                'revoked_by' => null,
                'version' => $grant->version + 1,
            ])->save();
            $this->record('business_network.granted', $connection, $grant, $old, $actor);

            return $grant;
        });
    }

    /** Take a module back at once: the provider's people lose it on their next request. */
    public function revoke(Connection $connection, Organization $client, string $module, User $actor): void
    {
        if ($connection->sideOf($client->getKey()) !== Connection::CLIENT) {
            throw ConnectionException::notYourSide();
        }
        DB::transaction(function () use ($connection, $module, $actor) {
            $grant = ConnectionGrant::query()->where('connection_id', $connection->getKey())->where('module', $module)->lockForUpdate()->first();
            if ($grant === null || $grant->revoked_at !== null) {
                return;
            }
            $old = $this->values($grant);
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'version' => $grant->version + 1])->save();
            $this->record('business_network.revoked', $connection, $grant, $old, $actor);
        });
    }

    private function isGrantable(ModuleDefinition $module): bool
    {
        return ! in_array($module->key, self::NEVER, true) && $module->category !== 'platform' && $module->permissions !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ConnectionGrant $grant): array
    {
        return [
            'module' => $grant->module,
            'permissions' => (array) $grant->permissions,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'revoked' => $grant->revoked_at !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     */
    private function record(string $action, Connection $connection, ConnectionGrant $grant, array $old, User $actor): void
    {
        foreach ([$connection->client_id, $connection->provider_id] as $organizationId) {
            $this->audit->record($action, $grant, old: $old, new: [...$this->values($grant), 'connection' => $connection->getKey()],
                actor: $actor, organizationId: $organizationId, partnerId: Organization::query()->whereKey($organizationId)->value('partner_id'));
        }
    }
}
