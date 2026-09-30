<?php

namespace App\Platform\Analytics;

use App\Platform\Analytics\Contracts\AnalyticsSink;
use Illuminate\Support\Facades\DB;

/**
 * Sends new platform rows to the analytics store (Phase 10-3), in order,
 * remembering how far it got per dataset and store (analytics_cursors).
 * Reads from the reporting replica when there is one.
 *
 * Dataset "audit_events": what happened where and when, for activity and
 * adoption analytics. Ids of partners and organizations only: no person,
 * address, device, old/new values or reason leaves the platform.
 */
class AnalyticsExport
{
    public const DATASETS = ['audit_events'];

    public function __construct(
        private AnalyticsSink $sink,
        private ReportingDatabase $reporting,
    ) {}

    /**
     * @return array<string, int> Rows sent per dataset in this run.
     */
    public function run(): array
    {
        if ($this->sink->name() === 'none') {
            return [];
        }

        return ['audit_events' => $this->auditEvents()];
    }

    private function auditEvents(): int
    {
        $batch = max(1, (int) config('analytics.batch'));
        $until = now()->subSeconds((int) config('analytics.lag_seconds'));
        $key = ['dataset' => 'audit_events', 'driver' => $this->sink->name()];
        $sent = 0;

        do {
            $cursor = DB::table('analytics_cursors')->where($key)->first();

            $entries = DB::connection($this->reporting->connection())->table('audit_logs')
                ->where('created_at', '<=', $until)
                ->when($cursor?->last_created_at !== null, fn ($query) => $query->where(fn ($after) => $after
                    ->where('created_at', '>', $cursor->last_created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $cursor->last_created_at)->where('id', '>', $cursor->last_id))))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batch)
                ->get(['id', 'created_at', 'partner_id', 'organization_id', 'action', 'target_type']);

            if ($entries->isEmpty()) {
                break;
            }

            $this->sink->send('audit_events', $entries->map(fn (object $entry) => [
                'id' => $entry->id,
                'created_at' => (string) $entry->created_at,
                'partner_id' => $entry->partner_id,
                'organization_id' => $entry->organization_id,
                'action' => $entry->action,
                'area' => strstr($entry->action, '.', true) ?: $entry->action,
                'target_type' => $entry->target_type,
            ])->values()->all());

            $last = $entries->last();
            DB::table('analytics_cursors')->updateOrInsert($key, [
                'last_created_at' => $last->created_at,
                'last_id' => $last->id,
                'exported' => (int) ($cursor->exported ?? 0) + $entries->count(),
                'updated_at' => now(),
            ]);
            $sent += $entries->count();
        } while ($entries->count() === $batch);

        return $sent;
    }
}
