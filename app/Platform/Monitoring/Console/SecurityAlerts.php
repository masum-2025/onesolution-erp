<?php

namespace App\Platform\Monitoring\Console;

use App\Platform\Monitoring\AlertService;
use App\Platform\Monitoring\AlertText;
use App\Platform\Monitoring\Models\SecurityAlert;
use Illuminate\Console\Command;

/**
 * The operators' view of security alerts: list the open ones, acknowledge
 * one once it was looked at (with a note of what was found).
 */
class SecurityAlerts extends Command
{
    protected $signature = 'security:alerts
        {--all : Include acknowledged alerts}
        {--ack= : Acknowledge this alert id}
        {--by= : Who acknowledges (default: the system user name)}
        {--note= : What was found or done}';

    protected $description = 'List open security alerts, or acknowledge one';

    public function handle(AlertService $alerts, AlertText $text): int
    {
        if ($id = $this->option('ack')) {
            $alert = SecurityAlert::query()->find($id);
            if ($alert === null) {
                $this->error("No alert {$id}.");

                return self::FAILURE;
            }

            $alerts->acknowledge($alert, (string) ($this->option('by') ?: get_current_user()), $this->option('note') ?: null);
            $this->info("Alert {$id} acknowledged.");

            return self::SUCCESS;
        }

        $rows = SecurityAlert::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('acknowledged_at'))
            ->latest('last_seen_at')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No open security alerts.');

            return self::SUCCESS;
        }

        $locale = (string) config('monitoring.platform.locale');
        $this->table(['Id', 'Severity', 'Alert', 'Count', 'Last seen (UTC)', 'Acknowledged'], $rows->map(fn (SecurityAlert $alert) => [
            $alert->getKey(),
            $alert->severity,
            $text->title($alert->kind, $locale),
            $alert->count,
            $alert->last_seen_at?->utc()->format('Y-m-d H:i'),
            $alert->acknowledged_at === null ? '' : $alert->acknowledged_by,
        ])->all());

        return self::SUCCESS;
    }
}
