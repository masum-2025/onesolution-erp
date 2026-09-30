<?php

namespace App\Platform\Audit\Shipping;

use App\Platform\Audit\Shipping\Contracts\AuditShipper;
use Illuminate\Support\Facades\DB;

/**
 * Copies new audit entries to the external store, in order, remembering how
 * far it got (audit_ship_cursors). Entries younger than the lag wait for the
 * next run, so one still being saved with an earlier time is not skipped.
 */
class AuditShipping
{
    public function __construct(private AuditShipper $shipper) {}

    /**
     * @return int Entries sent in this run.
     */
    public function run(): int
    {
        $name = $this->shipper->name();
        $batch = max(1, (int) config('audit.shipping.batch'));
        $until = now()->subSeconds((int) config('audit.shipping.lag_seconds'));
        $sent = 0;

        do {
            $cursor = DB::table('audit_ship_cursors')->where('driver', $name)->first();

            $entries = DB::table('audit_logs')
                ->where('created_at', '<=', $until)
                ->when($cursor?->last_created_at !== null, fn ($query) => $query->where(fn ($after) => $after
                    ->where('created_at', '>', $cursor->last_created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $cursor->last_created_at)->where('id', '>', $cursor->last_id))))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batch)
                ->get();

            if ($entries->isEmpty()) {
                break;
            }

            $this->shipper->ship($entries->map(fn ($entry) => $this->present($entry))->all());

            $last = $entries->last();
            DB::table('audit_ship_cursors')->updateOrInsert(['driver' => $name], [
                'last_created_at' => $last->created_at,
                'last_id' => $last->id,
                'shipped' => (int) ($cursor->shipped ?? 0) + $entries->count(),
                'updated_at' => now(),
            ]);
            $sent += $entries->count();
        } while ($entries->count() === $batch);

        return $sent;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $entry): array
    {
        return [
            'id' => $entry->id,
            'created_at' => $entry->created_at,
            'partner_id' => $entry->partner_id,
            'organization_id' => $entry->organization_id,
            'actor_user_id' => $entry->actor_user_id,
            'api_key_id' => $entry->api_key_id,
            'device_id' => $entry->device_id,
            'session_id' => $entry->session_id,
            'action' => $entry->action,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            'old_values' => $entry->old_values === null ? null : json_decode($entry->old_values, true),
            'new_values' => $entry->new_values === null ? null : json_decode($entry->new_values, true),
            'reason' => $entry->reason,
            'ip_address' => $entry->ip_address,
        ];
    }
}
