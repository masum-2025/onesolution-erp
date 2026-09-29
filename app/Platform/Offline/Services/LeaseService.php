<?php

namespace App\Platform\Offline\Services;

use App\Platform\Access\AccessResolver;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\OfflineLease;
use App\Platform\Offline\SyncRecords;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * An offline lease: what a device may do offline and until when, signed by
 * the server. It carries the person, organization and device, their
 * permissions, the rules the offline work needs (with a rule_version) and
 * its end (rule offline_mode.offline_lease_hours, which may differ per role
 * or person). Issued only inside the person's own tenant context, to a
 * device of theirs that is not removed, when they may work offline.
 */
class LeaseService
{
    /** Rules every offline device needs, besides those of the record kinds. */
    private const OWN_RULES = ['offline_mode.offline_lease_hours', 'offline_mode.max_cached_records', 'offline_mode.allow_offline_payments', 'offline_mode.sync_batch_max'];

    public function __construct(
        private CurrentContext $context,
        private AccessResolver $access,
        private SyncRecords $records,
        private RuleResolver $rules,
        private RuleCatalog $catalog,
        private RuleContextFactory $contexts,
        private LeaseSigner $signer,
    ) {}

    /**
     * @return array{token: string, lease: array<string, mixed>}
     */
    public function issue(Device $device): array
    {
        $organization = $this->context->organization();
        $user = $this->context->user();

        if ($device->user_id !== $user->getKey()) {
            throw OfflineException::deviceNotFound();
        }
        if ($device->organization_id !== $organization->getKey()) {
            throw OfflineException::wrongOrganization();
        }
        if ($device->isRevoked() || $device->mustWipe()) {
            throw OfflineException::deviceNotFound();
        }
        if (! Gate::allows('offline_mode.use', $organization)) {
            throw OfflineException::notAllowed();
        }

        $kinds = $this->records->available($organization);
        $keys = self::OWN_RULES;
        foreach ($kinds as $kind) {
            $keys = [...$keys, ...$this->records->provider($kind)->rules()];
        }
        $keys = array_values(array_unique(array_filter($keys, fn (string $key) => $this->catalog->has($key))));

        $ruleContext = $this->contexts->current();
        $snapshot = $this->rules->snapshot($keys, $ruleContext);
        $now = CarbonImmutable::now();
        $expires = $now->addHours((int) $snapshot['rules']['offline_mode.offline_lease_hours']);

        $lease = new OfflineLease;
        $lease->forceFill([
            'device_id' => $device->getKey(),
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'rule_version' => mb_substr($snapshot['rule_version'], 0, 200),
            'rule_keys' => $keys,
            'sensitive_hash' => $this->sensitiveHash($keys, $snapshot['rules']),
            'issued_at' => $now,
            'expires_at' => $expires,
        ])->save();

        $payload = [
            'lid' => $lease->getKey(),
            'dev' => $device->getKey(),
            'usr' => $user->getKey(),
            'org' => $organization->getKey(),
            'kinds' => $kinds,
            // So the device can refuse what the server would refuse anyway (Phase 7-2).
            'kind_info' => $this->kindInfo($kinds),
            'perms' => $this->access->effective(),
            'rules' => $snapshot['rules'],
            'rule_version' => $snapshot['rule_version'],
            'iat' => $now->getTimestamp(),
            'exp' => $expires->getTimestamp(),
        ];

        return ['token' => $this->signer->sign($payload), 'lease' => $payload];
    }

    /**
     * What the device keeps about a lease: the token (only the server trusts
     * it) and, readable, what it allows and until when.
     *
     * @param  array{token: string, lease: array<string, mixed>}  $issued
     * @return array<string, mixed>
     */
    public function describe(array $issued): array
    {
        return [
            'lease' => $issued['token'],
            'lease_id' => $issued['lease']['lid'],
            'expires_at' => CarbonImmutable::createFromTimestamp($issued['lease']['exp'])->toIso8601String(),
            'kinds' => $issued['lease']['kinds'],
            'kind_info' => $issued['lease']['kind_info'],
            'permissions' => $issued['lease']['perms'],
            'rules' => $issued['lease']['rules'],
            'rule_version' => $issued['lease']['rule_version'],
        ];
    }

    /**
     * Per kind: whether it is money, and the permission each action needs.
     *
     * @param  list<string>  $kinds
     * @return array<string, array{money: bool, permissions: array<string, string>}>
     */
    private function kindInfo(array $kinds): array
    {
        $info = [];
        foreach ($kinds as $kind) {
            $provider = $this->records->provider($kind);
            $info[$kind] = [
                'money' => $provider->isMoney(),
                'permissions' => array_combine(['create', 'update', 'delete'], array_map(fn (string $action) => $provider->permission($action), ['create', 'update', 'delete'])),
            ];
        }

        return $info;
    }

    /**
     * A fingerprint of the sensitive rules among these values: an operation
     * made under a lease whose sensitive rules changed since is not applied.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     */
    public function sensitiveHash(array $keys, array $values): string
    {
        $sensitive = [];
        foreach ($keys as $key) {
            if ($this->catalog->has($key) && $this->catalog->get($key)->sensitive) {
                $sensitive[$key] = $values[$key] ?? null;
            }
        }
        ksort($sensitive);

        return hash('sha256', json_encode($sensitive, JSON_THROW_ON_ERROR));
    }

    /**
     * The same fingerprint for the rules in force now, over the keys the lease had.
     */
    public function currentSensitiveHash(OfflineLease $lease): string
    {
        $keys = array_values(array_filter((array) $lease->rule_keys, fn (string $key) => $this->catalog->has($key)));

        return $this->sensitiveHash($keys, $this->rules->snapshot($keys, $this->contexts->current())['rules']);
    }
}
