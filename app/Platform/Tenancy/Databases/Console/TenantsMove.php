<?php

namespace App\Platform\Tenancy\Databases\Console;

use App\Platform\Tenancy\Databases\TenantMove;
use App\Platform\Tenancy\Databases\TenantMover;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Move a client's business data to another database: copy, verify every
 * table, then switch. The client can read but not change data meanwhile.
 */
class TenantsMove extends Command
{
    protected $signature = 'tenants:move
        {organization : The client\'s top organization id (group or stand-alone company)}
        {database? : A configured tenant database name; leave out with --shared}
        {--shared : Back to the main database}
        {--reason= : Why (required; goes to the audit log)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Move a client\'s business data to another database, with a verification report';

    public function handle(TenantMover $mover): int
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
        if (! $this->option('force') && ! $this->confirm("Move the business data of \"{$root->displayName()}\" to {$target}? It is read-only until the move ends.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $move = $mover->move($root, $database, $reason);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = (array) $move->report;
        $this->table(['Table', 'Rows (old)', 'Rows (new)', 'Checksums match'], array_map(
            fn (string $table, array $row) => [$table, $row['source_rows'], $row['target_rows'], $row['match'] ? 'yes' : 'NO'],
            array_keys($report['tables'] ?? []),
            $report['tables'] ?? [],
        ));

        if ($move->status !== TenantMove::COMPLETED) {
            $this->error('Verification failed: the copy was removed and the client stays where it was. Move id: '.$move->id);

            return self::FAILURE;
        }

        $this->info("Moved {$report['rows']} rows. The old copy is kept until tenants:purge-source may remove it. Move id: {$move->id}");

        return self::SUCCESS;
    }
}
