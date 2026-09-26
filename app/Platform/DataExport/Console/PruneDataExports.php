<?php

namespace App\Platform\DataExport\Console;

use App\Platform\DataExport\Services\DataExportService;
use Illuminate\Console\Command;

/**
 * Deletes export files past their retention (rule exports.retention_days).
 */
class PruneDataExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete data export files that are past their retention';

    public function handle(DataExportService $exports): int
    {
        $this->info($exports->pruneExpired().' data exports removed.');

        return self::SUCCESS;
    }
}
