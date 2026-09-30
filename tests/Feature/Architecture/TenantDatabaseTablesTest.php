<?php

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\TenantTables;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Fixtures\TenantNote;

/*
 * Phase 10: business tables follow their client into a dedicated or regional
 * database, so they must stand on their own there. These checks run over
 * every tenant migration (core, modules, test fixtures) and model.
 */

/**
 * Tables a migration file points foreign keys at.
 *
 * @return list<string>
 */
function referencedTables(string $source): array
{
    $tables = [];

    preg_match_all("/->constrained\\(\\s*'([a-z0-9_]+)'/", $source, $explicit);
    preg_match_all("/->(?:on|references\\([^)]*\\)->on)\\(\\s*'([a-z0-9_]+)'/", $source, $on);
    // foreignUlid('organization_id')->constrained() points at "organizations".
    preg_match_all("/foreign(?:Id|Ulid|Uuid)\\(\\s*'([a-z0-9_]+)_id'\\s*\\)(?:->\\w+\\([^)]*\\))*?->constrained\\(\\s*\\)/", $source, $implicit);

    return array_values(array_unique([...$explicit[1], ...$on[1], ...array_map(fn ($name) => Str::plural($name), $implicit[1])]));
}

/**
 * @return array<string, string> path => source of every class under these folders
 */
function sourcesIn(array $directories): array
{
    $existing = array_values(array_filter(array_map('base_path', $directories), 'is_dir'));
    $files = [];

    foreach ((new Finder)->files()->in($existing)->name('*.php')->exclude(['vendor', 'node_modules']) as $file) {
        $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $files;
}

it('finds the tenant tables (the checks below are not empty)', function () {
    expect(app(TenantTables::class)->all())->toContain('tenant_notes');
});

it('gives every tenant table an organization_id and an id (the move tool copies by them)', function () {
    foreach (app(TenantTables::class)->all() as $table) {
        expect(Schema::hasColumn($table, 'organization_id'))->toBeTrue("{$table} has no organization_id")
            ->and(Schema::hasColumn($table, 'id'))->toBeTrue("{$table} has no id");
    }
});

it('never points a tenant table at a platform table', function () {
    $tenant = app(TenantTables::class)->all();

    foreach (app(TenantTables::class)->migrationFiles() as $file) {
        $outside = array_diff(referencedTables((string) file_get_contents($file)), $tenant);

        expect($outside)->toBe([], basename($file).' has foreign keys to platform tables: '.implode(', ', $outside));
    }
});

it('never points a platform table at a tenant table', function () {
    $tenant = app(TenantTables::class)->all();
    $platform = [
        ...(glob(database_path('migrations/*.php')) ?: []),
        ...(glob(base_path('Modules/*/database/migrations/*.php')) ?: []),
        ...(glob(base_path('tests/Fixtures/migrations/*.php')) ?: []),
    ];

    foreach ($platform as $file) {
        $inside = array_intersect(referencedTables((string) file_get_contents($file)), $tenant);

        expect($inside)->toBe([], basename($file).' points at tenant tables: '.implode(', ', $inside));
    }
});

it('scopes every model in a tenant database to an organization', function () {
    $loose = [];

    foreach (sourcesIn(['app', 'Modules', 'tests/Fixtures']) as $path => $source) {
        if (preg_match('/^\s*use\s+[^;]*\bUsesTenantDatabase\b[^;]*;/m', $source) === 1 && preg_match('/^\s*use\s+[^;]*\bBelongsToOrganization\b[^;]*;/m', $source) !== 1) {
            $loose[] = $path;
        }
    }

    expect($loose)->toBe([]);
});

it('keeps module business data in the client\'s database', function () {
    $central = [];

    foreach (sourcesIn(['Modules']) as $path => $source) {
        if (preg_match('/^\s*use\s+[^;]*\bBelongsToOrganization\b[^;]*;/m', $source) === 1 && ! str_contains($source, 'UsesTenantDatabase')) {
            $central[] = $path;
        }
    }

    expect($central)->toBe([]);
});

it('creates the table of every tenant-database model in a tenant migration', function () {
    $tenant = app(TenantTables::class)->all();
    $models = [];

    foreach (sourcesIn(['app', 'Modules', 'tests/Fixtures']) as $source) {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) === 1
            && preg_match('/^(?:final\s+)?class\s+(\w+)\s+extends\s+Model\b/m', $source, $class) === 1) {
            $name = $namespace[1].'\\'.$class[1];
            if (class_exists($name) && in_array(UsesTenantDatabase::class, class_uses_recursive($name), true)) {
                $models[] = $name;
            }
        }
    }

    expect($models)->toContain(TenantNote::class);

    foreach ($models as $model) {
        expect(in_array(BelongsToOrganization::class, class_uses_recursive($model), true))->toBeTrue("{$model} is not organization-scoped")
            ->and($tenant)->toContain((new $model)->getTable());
    }
});
