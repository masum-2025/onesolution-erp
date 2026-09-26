<?php

namespace Tests;

use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        migrateDatabases as baseMigrateDatabases;
    }

    /**
     * Also run test-only fixture migrations (tests/Fixtures/migrations).
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--path' => [database_path('migrations'), base_path('tests/Fixtures/migrations')],
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
