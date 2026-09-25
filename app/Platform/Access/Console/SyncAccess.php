<?php

namespace App\Platform\Access\Console;

use App\Platform\Access\Models\Permission;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Access\PermissionCatalog;
use Illuminate\Console\Command;

/**
 * Mirror the permission catalog (core + module manifests) and the role
 * templates (database/seeders/data/role-templates.php) into the database.
 * Run on every deploy. Removed entries are marked deprecated, never deleted,
 * so roles that still hold them keep their history.
 */
class SyncAccess extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Sync permissions and role templates into the database';

    public function handle(PermissionCatalog $catalog): int
    {
        foreach ($catalog->all() as $permission) {
            Permission::query()->updateOrCreate(['key' => $permission->key], [
                'module_key' => $permission->moduleKey,
                'deprecated_at' => null,
            ]);
        }

        $deprecatedPermissions = Permission::query()
            ->whereNotIn('key', $catalog->keys())
            ->whereNull('deprecated_at')
            ->update(['deprecated_at' => now()]);

        $templates = require database_path('seeders/data/role-templates.php');

        foreach ($templates as $order => $template) {
            RoleTemplate::query()->updateOrCreate(['key' => $template['key']], [
                'sector_key' => $template['sector'],
                'permissions' => $template['permissions'],
                'sort_order' => $order,
                'deprecated_at' => null,
            ]);
        }

        $deprecatedTemplates = RoleTemplate::query()
            ->whereNotIn('key', array_column($templates, 'key'))
            ->whereNull('deprecated_at')
            ->update(['deprecated_at' => now()]);

        $this->info(sprintf(
            '%d permissions synced (%d deprecated), %d role templates synced (%d deprecated).',
            count($catalog->all()), $deprecatedPermissions, count($templates), $deprecatedTemplates,
        ));

        return self::SUCCESS;
    }
}
