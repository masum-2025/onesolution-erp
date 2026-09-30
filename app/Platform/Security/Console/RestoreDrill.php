<?php

namespace App\Platform\Security\Console;

use App\Platform\Security\Backups\BackupException;
use App\Platform\Security\Backups\RestoreDrill as Drill;
use Illuminate\Console\Command;

class RestoreDrill extends Command
{
    protected $signature = 'backup:restore-drill {--set= : A backup set id (default: the newest)}';

    protected $description = 'Restore a backup set into the staging database and check it (monthly drill)';

    public function handle(Drill $drill): int
    {
        try {
            $report = $drill->run($this->option('set') ?: null);
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $tables = $report['tables'];
        $this->line("Set {$report['set']} restored into {$report['drill_database']}.");
        $this->line("Tables: {$tables['checked']} checked, ".count($tables['missing']).' missing, '
            .count($tables['emptied']).' came back empty, '.count($tables['differences']).' with a different row count.');
        $this->line("Files: {$report['files']['found']} of {$report['files']['expected']}.");

        foreach ($tables['differences'] as $table => $difference) {
            $this->line("  {$table}: {$difference['backed_up']} backed up, {$difference['restored']} restored");
        }

        if (! $report['passed']) {
            $this->error('The restore drill FAILED. See the report next to the backup set.');

            return self::FAILURE;
        }

        $this->info('The restore drill passed.');

        return self::SUCCESS;
    }
}
