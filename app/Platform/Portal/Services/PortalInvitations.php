<?php

namespace App\Platform\Portal\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Notifications\Services\Mask;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Portal\Exceptions\PortalException;
use App\Platform\Portal\Jobs\SendPortalInvitation;
use App\Platform\Portal\Models\PortalInvitation;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\LocalDate;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A client invites someone to see one record in its portal. The invitation
 * is bound to the email or phone it is sent to: whoever uses it must have
 * verified that address (an existing account, or a code when creating one).
 * The link and the short code are shown or sent once; only hashes are kept.
 */
class PortalInvitations
{
    /** Easy to read aloud and type: no 0/O, 1/I/L. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 10;

    private const TOKEN_LENGTH = 48;

    public function __construct(
        private PortalSubjects $subjects,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{subject_type: string, subject_id: string, relation: string, name: string, channel: string, email?: string|null, phone?: string|null, country_code?: string|null, send: bool}  $data
     * @return array{invitation: PortalInvitation, code: string, token: string}
     */
    public function create(Organization $organization, User $actor, array $data): array
    {
        if (! $this->subjects->usable($data['subject_type'], $organization)) {
            throw PortalException::kindNotAvailable();
        }

        $provider = $this->subjects->provider($data['subject_type']);
        $subject = $provider->find($organization, $data['subject_id']) ?? throw PortalException::recordNotFound();
        if (! in_array($data['relation'], $provider->relations(), true)) {
            throw PortalException::relationNotAllowed();
        }

        $contact = $this->contact($data);
        $token = Str::random(self::TOKEN_LENGTH);
        $code = $this->newCode();
        $days = (int) $this->rules->get('client_portal.invitation_valid_days', $this->contexts->forOrganization($organization));

        $invitation = new PortalInvitation;
        $invitation->forceFill([
            'organization_id' => $organization->getKey(),
            'subject_type' => $data['subject_type'],
            'subject_id' => $subject->id,
            'relation' => $data['relation'],
            'name' => trim($data['name']),
            'channel' => $data['channel'],
            'contact' => $contact,
            'contact_hash' => self::hash($contact),
            'token_hash' => self::hash($token),
            'code_hash' => self::hash($code),
            'expires_at' => CarbonImmutable::now()->addDays($days),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record(
            action: 'portal.invited',
            target: $invitation,
            new: [
                'subject_type' => $invitation->subject_type,
                'subject_id' => $invitation->subject_id,
                'relation' => $invitation->relation,
                'channel' => $invitation->channel,
                'to' => $this->masked($invitation),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            actor: $actor,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        if ($data['send']) {
            $locale = $this->notifier->locale($organization);
            SendPortalInvitation::dispatch(
                $organization->getKey(),
                $invitation->channel,
                $contact,
                $invitation->name,
                $token,
                self::display($code),
                LocalDate::format($invitation->expires_at, $locale),
                $locale,
            )->afterCommit();
        }

        return ['invitation' => $invitation, 'code' => self::display($code), 'token' => $token];
    }

    public function revoke(PortalInvitation $invitation, User $actor): void
    {
        if ($invitation->revoked_at !== null || $invitation->used_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        $this->audit->record(
            action: 'portal.invitation_revoked',
            target: $invitation,
            actor: $actor,
            organizationId: $invitation->organization_id,
            partnerId: $invitation->organization?->partner_id,
        );
    }

    /**
     * An invitation by its link token or its short code (dashes, spaces and
     * letter case do not matter). Only open invitations are returned.
     */
    public function find(string $key): PortalInvitation
    {
        $key = trim($key);
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $key));

        $invitation = match (true) {
            strlen($key) === self::TOKEN_LENGTH => PortalInvitation::query()->where('token_hash', self::hash($key))->first(),
            strlen($code) === self::CODE_LENGTH => PortalInvitation::query()->where('code_hash', self::hash($code))->first(),
            default => null,
        };

        if ($invitation === null) {
            throw PortalException::invitationNotFound();
        }
        if (! $invitation->isOpen()) {
            throw PortalException::invitationClosed();
        }

        return $invitation;
    }

    /**
     * What the invited person may see before joining.
     *
     * @return array<string, mixed>
     */
    public function preview(PortalInvitation $invitation): array
    {
        $organization = $invitation->organization;
        $kind = $this->subjects->has($invitation->subject_type) ? $this->subjects->provider($invitation->subject_type) : null;

        return [
            'organization' => $organization->displayName(),
            'kind' => $kind?->label() ?? $invitation->subject_type,
            'relation' => $invitation->relation,
            'name' => $invitation->name,
            'channel' => $invitation->channel,
            'to' => $this->masked($invitation),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    public function masked(PortalInvitation $invitation): string
    {
        return $invitation->channel === 'sms' ? Mask::phone((string) $invitation->contact) : Mask::email((string) $invitation->contact);
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** ABCDE23456 -> ABCDE-23456, easier to read and type. */
    public static function display(string $code): string
    {
        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contact(array $data): string
    {
        if ($data['channel'] === 'sms') {
            return PhoneNumber::normalize((string) ($data['phone'] ?? ''), $data['country_code'] ?? null) ?? throw PortalException::badContact('phone');
        }

        $email = Str::lower(trim((string) ($data['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw PortalException::badContact('email');
        }

        return $email;
    }

    private function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }
}
