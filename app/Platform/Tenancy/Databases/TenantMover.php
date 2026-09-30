<?php

namespace App\Platform\Tenancy\Databases;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Moves a client tree's business data to another database with no loss:
 *
 *  1. the client is marked "moving": reading works, changes wait (503)
 *  2. every tenant table's rows of the tree are copied in id order
 *  3. both sides are compared per table (row count + checksum)
 *  4. only if all match, the placement switches to the new database;
 *     otherwise the copy is removed and nothing changes
 *
 * The old rows stay where they were until tenants:purge-source removes them
 * after the retention time; an earlier copy in the target (the client is
 * moving back) is replaced, because the current database holds the truth.
 */
class TenantMover
{
    public function __construct(
        private TenantDatabases $databases,
        private TenantPlacements $placements,
        private TenantTables $tables,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $database  A configured tenant database, or null for the main one.
     */
    public function move(Organization $root, ?string $database, string $reason, ?User $actor = null): TenantMove
    {
        if ($root->parent_id !== null) {
            throw new RuntimeException('Only a client\'s top organization (group or stand-alone company) can be moved.');
        }

        $this->databases->forget();
        $placement = $this->databases->placement((string) $root->getKey());
        $from = $placement?->database;
        $source = $this->databases->connectionFor($from);
        $target = $this->databases->connectionFor($database);

        if ($source === $target) {
            throw new RuntimeException('The client already uses this database.');
        }
        if ($placement?->status === PlacementStatus::Moving) {
            throw new RuntimeException('A move of this client is already under way.');
        }
        if (DB::connection($source)->getDriverName() !== DB::connection($target)->getDriverName()) {
            throw new RuntimeException('Both databases must use the same kind of server (MySQL or PostgreSQL).');
        }
        $this->placements->assertMigrated($target);

        $ids = $this->placements->subtreeIds($root);
        $staleCopy = $placement !== null && $placement->previous_retained_until !== null && $placement->previous_database === $database;

        if (! $staleCopy && $this->placements->hasBusinessData($root, $target)) {
            throw new RuntimeException('The target database already holds business data of this client. Nothing was changed.');
        }

        $move = TenantMove::create([
            'root_organization_id' => $root->getKey(),
            'from_database' => $from,
            'to_database' => $database,
            'status' => TenantMove::RUNNING,
            'reason' => $reason,
            'actor_user_id' => $actor?->getKey(),
            'started_at' => CarbonImmutable::now(),
        ]);

        $this->setStatus($root, PlacementStatus::Moving);
        $this->record('tenant_database.move_started', $root, $move, $reason, $actor, ['from' => $from, 'to' => $database]);

        $settle = (int) config('tenant_databases.move.settle_seconds');
        if ($settle > 0) {
            sleep($settle);
        }

        try {
            $removedStale = $staleCopy ? $this->deleteRows($target, $ids) : 0;
            $report = ['tables' => [], 'stale_rows_removed' => $removedStale];

            foreach ($this->tables->all() as $table) {
                $this->copyTable($table, $source, $target, $ids);
                $report['tables'][$table] = $this->compare($table, $source, $target, $ids);
            }

            $report['verified'] = collect($report['tables'])->every(fn (array $table) => $table['match']);
            $report['rows'] = array_sum(array_column($report['tables'], 'source_rows'));
        } catch (Throwable $exception) {
            $this->abort($root, $move, $target, $ids, $reason, $actor, ['error' => class_basename($exception)]);

            throw $exception;
        }

        if (! $report['verified']) {
            $this->abort($root, $move, $target, $ids, $reason, $actor, $report);

            return $move->refresh();
        }

        DB::transaction(function () use ($root, $database, $from, $move, $report, $reason, $actor) {
            TenantPlacement::query()->where('root_organization_id', $root->getKey())->update([
                'strategy' => $database === null ? DatabaseStrategy::Shared->value : DatabaseStrategy::Dedicated->value,
                'database' => $database,
                'status' => PlacementStatus::Active->value,
                'status_changed_at' => now(),
                'previous_database' => $from,
                'previous_retained_until' => now()->addDays((int) config('tenant_databases.move.retain_days')),
                'updated_at' => now(),
            ]);

            $move->forceFill(['status' => TenantMove::COMPLETED, 'report' => $report, 'finished_at' => CarbonImmutable::now()])->save();
            $this->record('tenant_database.moved', $root, $move, $reason, $actor, [
                'from' => $from, 'to' => $database, 'rows' => $report['rows'], 'tables' => count($report['tables']),
            ]);
        });
        $this->databases->forget();

        return $move->refresh();
    }

    /**
     * Remove the old copy left behind by the last move, once its retention
     * time is over. Returns the number of rows removed.
     */
    public function purgeSource(Organization $root, string $reason, ?User $actor = null): int
    {
        $this->databases->forget();
        $placement = $this->databases->placement((string) $root->getKey());

        if ($placement === null || $placement->previous_retained_until === null) {
            throw new RuntimeException('This client has no old copy to remove.');
        }
        if ($placement->status === PlacementStatus::Moving) {
            throw new RuntimeException('A move of this client is under way.');
        }
        if ($placement->previous_database === $placement->database) {
            throw new RuntimeException('The old copy is in the database the client uses now; it is not removed.');
        }
        if ($placement->previous_retained_until->isFuture()) {
            throw new RuntimeException('The old copy is kept until '.$placement->previous_retained_until->toDateTimeString().' UTC.');
        }

        $oldDatabase = $placement->previous_database;
        $removed = $this->deleteRows($this->databases->connectionFor($oldDatabase), $this->placements->subtreeIds($root));

        $placement->forceFill(['previous_database' => null, 'previous_retained_until' => null])->save();
        $this->audit->record(
            action: 'tenant_database.source_purged',
            target: $placement,
            new: ['database' => $oldDatabase, 'rows' => $removed],
            reason: $reason,
            actor: $actor,
            organizationId: (string) $root->getKey(),
            partnerId: (string) $root->partner_id,
        );
        $this->databases->forget();

        return $removed;
    }

    /**
     * Copy one table's rows of the tree, in id order, chunk by chunk.
     *
     * @param  list<string>  $ids
     */
    protected function copyTable(string $table, string $source, string $target, array $ids): void
    {
        $this->eachChunk($table, $source, $ids, function (array $rows) use ($table, $target) {
            DB::connection($target)->table($table)->insert($rows);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array{source_rows: int, target_rows: int, source_checksum: string, target_checksum: string, match: bool}
     */
    private function compare(string $table, string $source, string $target, array $ids): array
    {
        [$sourceRows, $sourceHash] = $this->checksum($table, $source, $ids);
        [$targetRows, $targetHash] = $this->checksum($table, $target, $ids);

        return [
            'source_rows' => $sourceRows,
            'target_rows' => $targetRows,
            'source_checksum' => $sourceHash,
            'target_checksum' => $targetHash,
            'match' => $sourceRows === $targetRows && hash_equals($sourceHash, $targetHash),
        ];
    }

    /**
     * Row count and a checksum over every column of every row, computed the
     * same way on both sides (no database-specific hashing).
     *
     * @param  list<string>  $ids
     * @return array{int, string}
     */
    private function checksum(string $table, string $connection, array $ids): array
    {
        $hash = hash_init('sha256');
        $count = 0;

        $this->eachChunk($table, $connection, $ids, function (array $rows) use ($hash, &$count) {
            foreach ($rows as $row) {
                ksort($row);
                hash_update($hash, json_encode(array_map(fn ($value) => match (true) {
                    $value === null => null,
                    is_bool($value) => $value ? '1' : '0',
                    default => (string) $value,
                }, $row))."\n");
                $count++;
            }
        });

        return [$count, hash_final($hash)];
    }

    /**
     * @param  list<string>  $ids
     * @param  callable(list<array<string, mixed>>): void  $callback
     */
    private function eachChunk(string $table, string $connection, array $ids, callable $callback): void
    {
        $size = max(1, (int) config('tenant_databases.move.chunk'));

        foreach (array_chunk($ids, 500) as $organizations) {
            $after = null;

            do {
                $rows = DB::connection($connection)->table($table)
                    ->whereIn('organization_id', $organizations)
                    ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
                    ->orderBy('id')
                    ->limit($size)
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all();

                if ($rows !== []) {
                    $callback($rows);
                    $after = $rows[array_key_last($rows)]['id'];
                }
            } while (count($rows) === $size);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function deleteRows(string $connection, array $ids): int
    {
        $removed = 0;

        foreach (array_reverse($this->tables->all()) as $table) {
            foreach (array_chunk($ids, 500) as $organizations) {
                $removed += DB::connection($connection)->table($table)->whereIn('organization_id', $organizations)->delete();
            }
        }

        return $removed;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $report
     */
    private function abort(Organization $root, TenantMove $move, string $target, array $ids, string $reason, ?User $actor, array $report): void
    {
        // The target copy goes; the client keeps using its current database.
        rescue(fn () => $this->deleteRows($target, $ids), report: false);
        $this->setStatus($root, PlacementStatus::Active);

        $move->forceFill(['status' => TenantMove::FAILED, 'report' => $report, 'finished_at' => CarbonImmutable::now()])->save();
        $this->record('tenant_database.move_failed', $root, $move, $reason, $actor, [
            'from' => $move->from_database, 'to' => $move->to_database,
            'tables_not_matching' => array_keys(array_filter($report['tables'] ?? [], fn (array $table) => ! $table['match'])),
        ]);
        $this->databases->forget();
    }

    private function setStatus(Organization $root, PlacementStatus $status): void
    {
        $placement = TenantPlacement::query()->firstOrNew(['root_organization_id' => $root->getKey()]);

        if (! $placement->exists) {
            $placement->fill(['strategy' => DatabaseStrategy::Shared, 'database' => null]);
        }

        $placement->fill(['status' => $status, 'status_changed_at' => now()])->save();
        $this->databases->forget();
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function record(string $action, Organization $root, TenantMove $move, string $reason, ?User $actor, array $new): void
    {
        $this->audit->record(
            action: $action,
            target: $move,
            new: $new,
            reason: $reason,
            actor: $actor,
            organizationId: (string) $root->getKey(),
            partnerId: (string) $root->partner_id,
        );
    }
}
