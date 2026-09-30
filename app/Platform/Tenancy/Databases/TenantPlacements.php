<?php

namespace App\Platform\Tenancy\Databases;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Decides where a client tree's business data lives. Every change is
 * audited. A client that already has business data changes database only
 * through the move tool (copy, verify, then switch), never by a plain
 * placement change.
 */
class TenantPlacements
{
    public function __construct(
        private TenantDatabases $databases,
        private TenantTables $tables,
        private AuditLogger $audit,
    ) {}

    /**
     * A new client of a region that has its own database lands there
     * (config tenant_databases.regions). Existing clients never change here.
     */
    public function placeNewRoot(Organization $root): ?TenantPlacement
    {
        $region = strtolower((string) ($root->region ?? config('tenancy.defaults.region')));
        $database = config('tenant_databases.regions.'.$region);

        if (! is_string($database) || $database === '') {
            return null;
        }

        return $this->write($root, DatabaseStrategy::Regional, $database, "New client in region {$region}", null);
    }

    /**
     * Put a client with no business data yet into another database (null =
     * the main one), e.g. a large client before it starts working.
     */
    public function place(Organization $root, ?string $database, string $reason, ?User $actor = null, DatabaseStrategy $strategy = DatabaseStrategy::Dedicated): TenantPlacement
    {
        if ($root->parent_id !== null) {
            throw new RuntimeException('Only a client\'s top organization (group or stand-alone company) has a database placement.');
        }

        $target = $this->databases->connectionFor($database);
        $current = $this->databases->forRoot((string) $root->getKey());

        if ($target === $current) {
            throw new RuntimeException('The client already uses this database.');
        }

        $this->assertMigrated($target);

        if ($this->hasBusinessData($root, $current)) {
            throw new RuntimeException('The client already has business data. Use tenants:move to copy and verify it.');
        }

        return $this->write($root, $database === null ? DatabaseStrategy::Shared : $strategy, $database, $reason, $actor);
    }

    public function assertMigrated(string $connection): void
    {
        $missing = array_values(array_filter($this->tables->all(), fn (string $table) => ! Schema::connection($connection)->hasTable($table)));

        if ($missing !== []) {
            throw new RuntimeException("Database {$connection} is missing tables (run tenants:migrate): ".implode(', ', $missing));
        }
    }

    /**
     * Whether any tenant table holds a row of this client in that database.
     */
    public function hasBusinessData(Organization $root, string $connection): bool
    {
        $ids = $this->subtreeIds($root);

        foreach ($this->tables->all() as $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                if (DB::connection($connection)->table($table)->whereIn('organization_id', $chunk)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function subtreeIds(Organization $root): array
    {
        return Organization::query()->subtreeOf($root)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function write(Organization $root, DatabaseStrategy $strategy, ?string $database, string $reason, ?User $actor): TenantPlacement
    {
        return DB::transaction(function () use ($root, $strategy, $database, $reason, $actor) {
            $placement = TenantPlacement::query()->firstOrNew(['root_organization_id' => $root->getKey()]);
            $old = $placement->exists ? ['strategy' => $placement->strategy->value, 'database' => $placement->database] : ['strategy' => DatabaseStrategy::Shared->value, 'database' => null];

            $placement->fill([
                'strategy' => $strategy,
                'database' => $database,
                'status' => PlacementStatus::Active,
                'status_changed_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'tenant_database.placed',
                target: $placement,
                old: $old,
                new: ['strategy' => $strategy->value, 'database' => $database],
                reason: $reason,
                actor: $actor,
                organizationId: (string) $root->getKey(),
                partnerId: (string) $root->partner_id,
            );

            $this->databases->forget();

            return $placement;
        });
    }
}
