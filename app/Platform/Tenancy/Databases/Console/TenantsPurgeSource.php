<?php

namespace App\Platform\Tenancy\Databases\Console;

use App\Platform\Tenancy\Databases\TenantMover;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Remove the old copy a move left behind, after its retention time. The
 * organization id must be typed again as confirmation.
 */
class TenantsPurgeSource extends Command
{
    protected $signature = 'tenants:purge-source
        {organization : The client\'s top organization id}
        {--confirm= : Type the organization id again}
        {--reason= : Why (required; goes to the audit log)}';

    protected $description = 'Remove the old copy of a moved client\'s business data (after the retention time)';

    public function handle(TenantMover $mover): int
    {
        $id = (string) $this->argument('organization');
        $reason = trim((string) $this->option('reason'));

        if ($this->option('confirm') !== $id) {
            $this->error('Type the organization id again with --confirm to remove the old copy. Nothing was removed.');

            return self::INVALID;
        }
        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason (at least 5 characters); it is kept in the audit log.');

            return self::INVALID;
        }

        $root = Organization::query()->find($id);
        if ($root === null) {
            $this->error("No organization found for \"{$id}\".");

            return self::FAILURE;
        }

        try {
            $removed = $mover->purgeSource($root, $reason);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Removed {$removed} rows of the old copy.");

        return self::SUCCESS;
    }
}
