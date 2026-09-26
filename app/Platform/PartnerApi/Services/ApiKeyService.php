<?php

namespace App\Platform\PartnerApi\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Str;

/**
 * Partner API keys: osk_{prefix}_{secret}. The prefix finds the key and is
 * shown in lists; the whole key is compared by hash in constant time. A key
 * works only while it is not revoked or expired, its partner is active and
 * its creator is still an active owner of that partner.
 */
class ApiKeyService
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /**
     * @param  list<string>  $scopes
     * @return array{key: string, record: PartnerApiKey}
     */
    public function create(Partner $partner, string $name, array $scopes, User $actor): array
    {
        $prefix = 'osk_'.Str::lower(Str::random(8));
        $key = $prefix.'_'.Str::random(40);
        $days = (int) $this->rules->get('partners.api_key_days', $this->contexts->forPartner($partner));

        $record = new PartnerApiKey;
        $record->forceFill([
            'partner_id' => $partner->getKey(),
            'name' => $name,
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $key),
            'scopes' => array_values(array_unique($scopes)),
            'expires_at' => now()->addDays($days),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record(
            action: 'partner.api_key_created',
            target: $record,
            new: ['name' => $name, 'prefix' => $prefix, 'scopes' => $record->scopes, 'expires_at' => $record->expires_at->toIso8601String()],
            actor: $actor,
            partnerId: $partner->getKey(),
        );

        return ['key' => $key, 'record' => $record];
    }

    public function revoke(PartnerApiKey $key, User $actor): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
        $this->audit->record(action: 'partner.api_key_revoked', target: $key, new: ['name' => $key->name, 'prefix' => $key->prefix], actor: $actor, partnerId: $key->partner_id);
    }

    /**
     * The key a bearer token stands for, if it may be used now.
     *
     * @return array{key: PartnerApiKey, creator: User}|null
     */
    public function authenticate(string $token): ?array
    {
        if (! preg_match('/^(osk_[a-z0-9]{8})_[A-Za-z0-9]{40}$/', $token, $match)) {
            return null;
        }

        $key = PartnerApiKey::query()->where('prefix', $match[1])->first();
        if ($key === null || ! hash_equals($key->secret_hash, hash('sha256', $token)) || $key->status() !== 'active') {
            return null;
        }

        $partnerActive = Partner::query()->whereKey($key->partner_id)->where('status', PartnerStatus::Active)->exists();
        $creatorOwner = PartnerUser::query()
            ->where('partner_id', $key->partner_id)
            ->where('user_id', $key->created_by)
            ->where('status', MembershipStatus::Active)
            ->where('role', PartnerUserRole::Owner)
            ->exists();
        $creator = User::query()->find($key->created_by);

        if (! $partnerActive || ! $creatorOwner || $creator === null) {
            return null;
        }

        // Written at most once a minute, so busy keys do not write on every call.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return ['key' => $key, 'creator' => $creator];
    }
}
