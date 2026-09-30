<?php

namespace Tests;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Databases\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        migrateDatabases as baseMigrateDatabases;
    }

    /** Test-only business tables that follow their client (Phase 10). */
    public const FIXTURE_TENANT_MIGRATIONS = 'tests/Fixtures/migrations/tenant';

    /**
     * Pages render without built frontend assets (CI does not build them
     * before the PHP tests; the frontend job builds and checks them).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->push('tenant_databases.migration_paths', base_path(self::FIXTURE_TENANT_MIGRATIONS));

        return $app;
    }

    /**
     * Also run test-only fixture migrations (tests/Fixtures/migrations) and
     * the tenant tables (core, modules, fixtures).
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--path' => [
                database_path('migrations'),
                base_path('tests/Fixtures/migrations'),
                ...app(TenantTables::class)->migrationPaths(),
            ],
            '--realpath' => true,
        ]);
    }

    /**
     * Mirror the permission catalog and role templates once per run, like a
     * deploy does (role rows reference permissions by key).
     */
    protected function migrateDatabases()
    {
        $this->baseMigrateDatabases();

        $this->artisan('access:sync');
        $this->artisan('packaging:sync');
        $this->artisan('billing:sync-prices');
        $this->artisan('legal:sync');
    }

    /**
     * Send the next request with this API token, as a fresh client would:
     * no cached guard user and no leftover tenant context.
     */
    public function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->make(CurrentContext::class)->clear();

        return $this->withToken($token);
    }
}
