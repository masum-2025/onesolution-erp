<?php

namespace App\Platform\Tenancy\Databases\Console;

use App\Platform\Tenancy\Databases\DatabaseStrategy;
use App\Platform\Tenancy\Databases\TenantPlacements;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Give a client with no business data yet its own (or a regional) database.
 * A client that already has data moves with tenants:move instead.
 */
class TenantsPlace extends Command
{
    protected $signature = 'tenants:place
        {organization : The client\'s top organization id (group or stand-alone company)}
        {database? : A configured tenant database name; leave out with --shared}
        {--shared : Back to the main database}
        {--regional : Mark the placement as the region\'s database}
        {--reason= : Why (required; goes to the audit log)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Choose the database of a client that has no business data yet';

    public function handle(TenantPlacements $placements): int
    {
        $reason = trim((string) $this->option('reason'));
        $database = $this->option('shared') ? null : $this->argument('database');

        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason (at least 5 characters); it is kept in the audit log.');

            return self::INVALID;
        }

        if ($database === null && ! $this->option('shared')) {
            $this->error('Name a tenant database, or use --shared for the main database.');

            return self::INVALID;
        }

        $root = Organization::query()->find($this->argument('organization'));

        if ($root === null) {
            $this->error('No organization found for "'.$this->argument('organization').'".');

            return self::FAILURE;
        }

        $target = $database ?? 'the main database';
        if (! $this->option('force') && ! $this->confirm("Keep the business data of \"{$root->displayName()}\" in {$target} from now on?")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $placement = $placements->place($root, $database, $reason, strategy: $this->option('regional') ? DatabaseStrategy::Regional : DatabaseStrategy::Dedicated);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Placed: {$placement->strategy->value}".($placement->database ? " ({$placement->database})" : '').'.');

        return self::SUCCESS;
    }
}
