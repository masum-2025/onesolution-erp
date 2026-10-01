<?php

namespace App\Platform\Security\Console;

use App\Platform\Security\EndpointInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The endpoint inventory for the penetration-test scope (Phase 11).
 */
class SecurityEndpoints extends Command
{
    public const FILE = 'docs/security/endpoints.md';

    protected $signature = 'security:endpoints {--write : Update docs/security/endpoints.md}';

    protected $description = 'List every HTTP endpoint with how it is protected';

    public function handle(EndpointInventory $inventory): int
    {
        if (! $this->option('write')) {
            $this->table(['Method', 'Path', 'Access', 'Context', 'Module', 'Limits', 'Step-up'], array_map(
                fn (array $row) => [...array_slice($row, 0, 6), $row['step_up'] ? 'yes' : ''],
                $inventory->all(),
            ));

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(base_path('docs/security'));
        File::put(base_path(self::FILE), $inventory->markdown());
        $this->info('Wrote '.self::FILE.'.');

        return self::SUCCESS;
    }
}
