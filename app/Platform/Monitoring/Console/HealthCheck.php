<?php

namespace App\Platform\Monitoring\Console;

use App\Platform\Monitoring\HealthReport;
use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'health:report {--json : Print the report as JSON}';

    protected $description = 'Queue, failed jobs, sync errors, rule cache, backups, scheduler and open alerts (exit 1 on a failing check)';

    public function handle(HealthReport $health): int
    {
        $report = $health->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Value', 'Detail'], array_map(fn (string $name, array $check) => [
                $name,
                strtoupper($check['status']),
                is_array($check['value']) ? json_encode($check['value']) : (string) ($check['value'] ?? ''),
                $check['detail'] ?? '',
            ], array_keys($report['checks']), $report['checks']));
            $this->line('Overall: '.strtoupper($report['status']));
        }

        return $report['status'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
