<?php

namespace App\Platform\Audit\Console;

use App\Platform\Audit\AuditRetention;
use App\Platform\Audit\Exports\AuditExportService;
use Illuminate\Console\Command;

class PruneAudit extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Remove audit entries past each organization\'s retention (advanced_audit) and expired audit exports';

    public function handle(AuditRetention $retention, AuditExportService $exports): int
    {
        $removed = $retention->prune();
        $files = $exports->prune();

        $this->info(count($removed).' organizations pruned ('
            .array_sum(array_column($removed, 'general')).' entries, '
            .array_sum(array_column($removed, 'money')).' money entries); '
            ."{$files} audit exports closed.");

        return self::SUCCESS;
    }
}
