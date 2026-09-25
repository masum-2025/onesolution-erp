<?php

namespace App\Platform\Audit;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes append-only audit entries. Callers pass only the fields that
 * changed; never pass passwords, tokens or other secrets.
 */
class AuditLogger
{
    public function __construct(private CurrentContext $context) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?User $actor = null,
        ?string $organizationId = null,
        ?string $partnerId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'partner_id' => $partnerId ?? ($this->context->hasOrganization() || $this->context->hasPartnerConsole()
                ? $this->context->partner()->getKey()
                : null),
            'organization_id' => $organizationId ?? ($this->context->hasOrganization()
                ? $this->context->organization()->getKey()
                : null),
            'actor_user_id' => ($actor ?? $this->context->user())?->getKey(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
    }
}
