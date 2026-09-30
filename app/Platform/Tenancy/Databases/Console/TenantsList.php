<?php

namespace App\Platform\Tenancy\Databases\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacement;
use Illuminate\Console\Command;

/**
 * Which clients keep their business data outside the main database.
 */
class TenantsList extends Command
{
    protected $signature = 'tenants:list';

    protected $description = 'List tenant databases and the clients placed in them';

    public function handle(TenantDatabases $databases): int
    {
        $this->line('Configured tenant databases: '.($databases->names() === [] ? 'none' : implode(', ', $databases->names())));
        $this->line('Region databases: '.(config('tenant_databases.regions') === [] ? 'none' : json_encode(config('tenant_databases.regions'))));

        $rows = TenantPlacement::query()->with('root')->orderBy('created_at')->get()->map(fn (TenantPlacement $placement) => [
            $placement->root_organization_id,
            $placement->root?->displayName() ?? '?',
            $placement->strategy->value,
            $placement->database ?? 'main',
            $placement->status->value,
            $placement->previous_database ?? '',
        ]);

        if ($rows->isEmpty()) {
            $this->line('Every client uses the main database.');

            return self::SUCCESS;
        }

        $this->table(['Client', 'Name', 'Strategy', 'Database', 'Status', 'Old copy in'], $rows->all());

        return self::SUCCESS;
    }
}
