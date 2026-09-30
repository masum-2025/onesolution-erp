<?php

namespace App\Platform\Tenancy\Databases;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Picks the database connection for tenant business data (models using
 * UsesTenantDatabase). Platform data (organizations, people, rules, billing,
 * audit) always stays in the main database.
 *
 * Placements are read fresh once per request or job (scoped binding), never
 * from a shared cache, so a finished move takes effect at once everywhere.
 * An unknown database name fails closed: data is never read from, or written
 * to, another database by accident.
 */
final class TenantDatabases
{
    public const CONNECTION_PREFIX = 'tenant_';

    /** @var array<string, TenantPlacement|false> root id => placement (false = none) */
    private array $placements = [];

    /** @var array<string, string> organization id => root id */
    private array $roots = [];

    /** @var list<string> Connections forced by system code (innermost last). */
    private array $forced = [];

    public function __construct(private CurrentContext $context) {}

    /** The main database connection (platform data, and every shared client). */
    public function central(): string
    {
        return (string) config('database.default');
    }

    /**
     * Define the connections of every configured tenant database (at boot).
     */
    public static function registerConfigured(): void
    {
        foreach ((array) config('tenant_databases.databases', []) as $name => $overrides) {
            self::register((string) $name, (array) $overrides);
        }
    }

    /**
     * Define one tenant database connection: the main connection's settings
     * with the given ones on top. Returns the connection name.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function register(string $name, array $overrides = []): string
    {
        $connection = self::CONNECTION_PREFIX.$name;
        $base = (array) config('database.connections.'.config('database.default'));

        config([
            'database.connections.'.$connection => array_merge($base, $overrides),
            'tenant_databases.databases.'.$name => $overrides,
        ]);

        return $connection;
    }

    public function isConfigured(string $name): bool
    {
        return array_key_exists($name, (array) config('tenant_databases.databases', []));
    }

    /**
     * @return list<string> Configured tenant database names.
     */
    public function names(): array
    {
        return array_map('strval', array_keys((array) config('tenant_databases.databases', [])));
    }

    /**
     * The connection for a database name (null = the main database).
     */
    public function connectionFor(?string $database): string
    {
        if ($database === null) {
            return $this->central();
        }

        if (! $this->isConfigured($database)) {
            throw new RuntimeException("Tenant database \"{$database}\" is not configured (TENANT_DATABASES).");
        }

        return self::CONNECTION_PREFIX.$database;
    }

    public function placement(string $rootId): ?TenantPlacement
    {
        if (! array_key_exists($rootId, $this->placements)) {
            $this->placements[$rootId] = TenantPlacement::query()->where('root_organization_id', $rootId)->first() ?? false;
        }

        return $this->placements[$rootId] ?: null;
    }

    public function forRoot(string $rootId): string
    {
        return $this->connectionFor($this->placement($rootId)?->database);
    }

    public function rootOf(string $organizationId): string
    {
        return $this->roots[$organizationId] ??= (string) (Organization::query()->whereKey($organizationId)->value('root_id')
            ?? throw new RuntimeException("Unknown organization {$organizationId}."));
    }

    public function forOrganization(Organization|string $organization): string
    {
        return $this->forRoot($organization instanceof Organization
            ? (string) $organization->root_id
            : $this->rootOf($organization));
    }

    /**
     * The connection a tenant model uses: forced by system code, else the
     * current context's client tree, else the record's own organization.
     */
    public function forModel(Model $model): string
    {
        if ($this->forced !== []) {
            return $this->forced[array_key_last($this->forced)];
        }

        if ($this->context->hasOrganization()) {
            return $this->forRoot((string) $this->context->organization()->root_id);
        }

        $organizationId = $model->getAttribute('organization_id');

        return $organizationId ? $this->forOrganization((string) $organizationId) : $this->central();
    }

    /**
     * Run system code (jobs, moves, reports) against one client's database.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(Organization|string $organization, callable $callback): mixed
    {
        return $this->onConnection($this->forOrganization($organization), $callback);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function onConnection(string $connection, callable $callback): mixed
    {
        $this->forced[] = $connection;

        try {
            return $callback();
        } finally {
            array_pop($this->forced);
        }
    }

    /** Whether the client's business data is being copied to another database right now. */
    public function isMoving(Organization|string $organization): bool
    {
        $rootId = $organization instanceof Organization ? (string) $organization->root_id : $this->rootOf($organization);

        return $this->placement($rootId)?->status === PlacementStatus::Moving;
    }

    /**
     * Refuse business-data writes while the client's data is being moved.
     */
    public function assertWritable(Model $model): void
    {
        $organizationId = $model->getAttribute('organization_id');

        if (! $organizationId) {
            return;
        }

        if ($this->isMoving((string) $organizationId)) {
            throw new TenantDataMoving;
        }
    }

    /**
     * Drop remembered placements (after a placement changes in this process).
     */
    public function forget(): void
    {
        $this->placements = [];
        $this->roots = [];
    }
}
