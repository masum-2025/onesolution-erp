<?php

namespace App\Platform\Monitoring;

use App\Platform\Monitoring\Jobs\DeliverSecurityAlert;
use App\Platform\Monitoring\Models\SecurityAlert;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Raises alerts (Phase 9-2): a new one is stored and delivered; while one of
 * the same kind and group is still active (seen within its cooldown and not
 * acknowledged), later events only count up it, so nobody is told twice.
 */
class AlertService
{
    /** Context fields kept with an alert: ids and codes, never personal data beyond the address. */
    private const DETAILS = ['event', 'code', 'route', 'method', 'host', 'ip', 'user_id', 'organization_id', 'partner_id', 'api_key_id', 'requested_organization_id', 'rule', 'module', 'target_type', 'target_id', 'audit_id', 'source'];

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    public function raise(string $kind, array $definition, string $group, array $context, int $count): SecurityAlert
    {
        $fingerprint = sha1($kind.'|'.$group);
        $details = Arr::only($context, self::DETAILS);

        return DB::transaction(function () use ($kind, $definition, $fingerprint, $details, $context, $count) {
            $open = SecurityAlert::query()
                ->where('fingerprint', $fingerprint)
                ->whereNull('acknowledged_at')
                ->where('last_seen_at', '>=', now()->subSeconds((int) $definition['cooldown']))
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                $open->forceFill(['count' => $open->count + 1, 'last_seen_at' => now(), 'details' => $details])->save();

                return $open;
            }

            $alert = SecurityAlert::create([
                'kind' => $kind,
                'severity' => $definition['severity'],
                'fingerprint' => $fingerprint,
                'partner_id' => $context['partner_id'] ?? null,
                'organization_id' => $context['organization_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'count' => $count,
                'details' => $details,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            DeliverSecurityAlert::dispatch($alert->getKey())->afterCommit();

            return $alert;
        });
    }

    public function acknowledge(SecurityAlert $alert, string $by, ?string $note = null): void
    {
        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $by, 'note' => $note])->save();
    }
}
