<?php

namespace App\Platform\Notifications\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A partner's own sending domain: we make its DKIM key, the partner publishes
 * four DNS records, and mail goes out from the domain only while all four
 * check out. Until then (and if a check later fails) mail comes from our
 * address under the partner's name, so nothing is ever lost.
 */
class MailDomainService
{
    public function __construct(
        private DnsTxtLookup $dns,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function add(Partner $partner, string $domain, User $actor): PartnerMailDomain
    {
        if (! (bool) $this->rules->get('mail.custom_domain_allowed', $this->contexts->forPartner($partner))) {
            throw NotificationException::customDomainNotAllowed();
        }

        $domain = strtolower(trim($domain));
        if (PartnerMailDomain::query()->where('partner_id', $partner->getKey())->orWhere('domain', $domain)->exists()) {
            throw NotificationException::domainExists();
        }

        [$private, $public] = $this->keyPair();

        return DB::transaction(function () use ($partner, $domain, $actor, $private, $public) {
            $record = new PartnerMailDomain;
            $record->forceFill([
                'partner_id' => $partner->getKey(),
                'domain' => $domain,
                'verification_token' => Str::random(40),
                'dkim_selector' => 'os'.now()->format('ym'),
                'dkim_private_key' => $private,
                'dkim_public_key' => $public,
                'local_part' => 'no-reply',
                'checks' => array_fill_keys(PartnerMailDomain::CHECKS, false),
                'status' => PartnerMailDomain::PENDING,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(action: 'partner.mail_domain_added', target: $record, new: ['domain' => $domain], actor: $actor, partnerId: $partner->getKey());

            return $record;
        });
    }

    /**
     * Look the four records up. All pass: the domain is used for sending.
     * Any fails: it is not (again), and the failed ones are named.
     */
    public function verify(PartnerMailDomain $domain, ?User $actor = null): PartnerMailDomain
    {
        $records = $domain->records();
        $found = fn (string $name) => array_map(fn ($value) => trim(preg_replace('/\s+/', ' ', $value)), $this->dns->txt($name));
        $normalise = fn (string $value) => strtolower(str_replace(' ', '', $value));

        $checks = [
            'ownership' => in_array($records['ownership']['value'], $found($records['ownership']['name']), true),
            // One SPF record that lets our servers send.
            'spf' => collect($found($records['spf']['name']))->contains(fn ($value) => str_starts_with(strtolower($value), 'v=spf1')
                && str_contains(strtolower($value), 'include:'.strtolower((string) config('notifications.mail.spf_include')))),
            // The DKIM public key must be exactly ours.
            'dkim' => collect($found($records['dkim']['name']))->contains(fn ($value) => str_contains($normalise($value), 'p='.strtolower($domain->dkim_public_key))),
            // Any DMARC policy will do; the partner chooses how strict.
            'dmarc' => collect($found($records['dmarc']['name']))->contains(fn ($value) => str_starts_with($normalise($value), 'v=dmarc1')),
        ];

        $passed = ! in_array(false, $checks, true);
        $was = $domain->status;

        $domain->forceFill([
            'checks' => $checks,
            'status' => $passed ? PartnerMailDomain::ACTIVE : PartnerMailDomain::PENDING,
            'verified_at' => $passed ? ($domain->verified_at ?? now()) : null,
            'last_checked_at' => now(),
        ])->save();

        if ($was !== $domain->status) {
            $this->audit->record(
                action: $passed ? 'partner.mail_domain_verified' : 'partner.mail_domain_failed',
                target: $domain,
                new: ['domain' => $domain->domain, 'checks' => $checks],
                actor: $actor,
                partnerId: $domain->partner_id,
            );
        }

        if (! $passed) {
            throw NotificationException::verificationFailed(array_keys(array_filter($checks, fn ($ok) => ! $ok)));
        }

        return $domain;
    }

    /**
     * @param  array{local_part?: string, from_name?: string|null, reply_to?: string|null}  $changes
     */
    public function updateSender(PartnerMailDomain $domain, array $changes, User $actor): PartnerMailDomain
    {
        $old = $domain->only(['local_part', 'from_name', 'reply_to']);
        $domain->fill($changes)->save();

        $this->audit->record(action: 'partner.mail_sender_changed', target: $domain, old: $old, new: $domain->only(['local_part', 'from_name', 'reply_to']), actor: $actor, partnerId: $domain->partner_id);

        return $domain;
    }

    public function remove(PartnerMailDomain $domain, string $reason, User $actor): void
    {
        DB::transaction(function () use ($domain, $reason, $actor) {
            $this->audit->record(action: 'partner.mail_domain_removed', target: $domain, old: ['domain' => $domain->domain, 'status' => $domain->status], reason: $reason, actor: $actor, partnerId: $domain->partner_id);
            $domain->delete();
        });
    }

    /**
     * @return array{0: string, 1: string} PEM private key, base64 public key (DNS form).
     */
    private function keyPair(): array
    {
        $options = ['private_key_bits' => (int) config('notifications.mail.dkim_bits', 2048), 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // Some PHP builds (often on Windows) ship without an OpenSSL config file.
        if ($key === false) {
            $options['config'] = resource_path('ssl/openssl.cnf');
            $key = openssl_pkey_new($options);
        }

        if ($key === false || ! openssl_pkey_export($key, $private, null, array_intersect_key($options, ['config' => true]))) {
            throw new RuntimeException('Could not create a DKIM key.');
        }

        $public = openssl_pkey_get_details($key)['key'];
        $public = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $public);

        return [$private, $public];
    }
}
