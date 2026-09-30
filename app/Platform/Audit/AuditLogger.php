<?php

namespace App\Platform\Audit;

use App\Models\User;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes append-only audit entries. Callers pass only the fields that
 * changed; never pass passwords, tokens or other secrets.
 */
class AuditLogger
{
    /** Request attribute naming the offline device whose changes are being applied. */
    public const DEVICE_ATTRIBUTE = 'audit_device_id';

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
            // A change made by a partner's system through an API key names the key.
            'api_key_id' => $request->attributes->get('partner_api_key')?->getKey(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'device_id' => $request->attributes->get(self::DEVICE_ATTRIBUTE),
            'session_id' => $this->sessionId($request),
        ]);
    }

    /**
     * The browser session (My account > devices) the change was made in, if any.
     */
    private function sessionId(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return UserSession::query()->where('session_hash', UserSession::hashOf($request->session()->getId()))->value('id');
    }
}
