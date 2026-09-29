<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Branding\BrandResolver;
use App\Platform\Identity\Exceptions\TwoFactorException;
use App\Platform\Identity\Models\Passkey;
use App\Platform\Partners\HostContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkeys (WebAuthn, Phase 8-1). Each ceremony is two calls: options (a
 * fresh challenge kept in the session, single use, short-lived) and the
 * browser's answer, checked against exactly this address (origin and
 * rp_id): a partner's own domain has its own passkeys.
 *
 *  - The device must verify the person (fingerprint, face, PIN), so a
 *    passkey is a full second step on its own.
 *  - Passkeys are discoverable: signing in needs no email first.
 *  - Only "none" attestation: which device made it is not recorded.
 *
 * Purposes: "register" (add one), "login" (sign in, or the second step of a
 * password sign-in), "confirm" (step-up for a sensitive action).
 */
class PasskeyService
{
    private const SESSION_KEY = 'passkey.ceremony';

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private AuditLogger $audit,
        private BrandResolver $brands,
        private HostContext $host,
        private TwoFactorService $twoFactor,
    ) {}

    public function available(Request $request): bool
    {
        return $request->isSecure() || in_array($request->getHost(), config('identity.two_factor.passkey_insecure_hosts'), true);
    }

    /**
     * @return array<string, mixed> Options for navigator.credentials.create().
     */
    public function creationOptions(Request $request, User $user): array
    {
        $this->ensureAvailable($request);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($this->brandName(), $request->getHost()),
            user: PublicKeyCredentialUserEntity::create($user->email ?? $user->phone ?? $user->getKey(), $user->getKey(), $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->descriptors($user, $request->getHost()),
            timeout: $this->timeoutMs(),
        );

        return $this->remember($request, 'register', $user, $options);
    }

    /**
     * @param  array<string, mixed>  $credential  The browser's answer (JSON form).
     */
    public function register(Request $request, User $user, string $name, array $credential): Passkey
    {
        $options = $this->take($request, 'register', $user, PublicKeyCredentialCreationOptions::class);
        $response = $this->load($credential)->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw TwoFactorException::passkeyFailed();
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($request)->creationCeremony())
                ->check($response, $options, $request->getHost());
        } catch (Throwable) {
            throw TwoFactorException::passkeyFailed();
        }

        $credentialId = self::encode($record->publicKeyCredentialId);
        if (Passkey::query()->where('rp_id', $request->getHost())->where('credential_hash', Passkey::hashId($credentialId))->exists()) {
            throw TwoFactorException::passkeyFailed();
        }

        return DB::transaction(function () use ($request, $user, $name, $record, $credentialId) {
            $passkey = new Passkey;
            $passkey->forceFill([
                'user_id' => $user->getKey(),
                'name' => $name,
                'rp_id' => $request->getHost(),
                'credential_id' => $credentialId,
                'credential_hash' => Passkey::hashId($credentialId),
                'public_key' => self::encode($record->credentialPublicKey),
                'transports' => $record->transports,
                'aaguid' => $record->aaguid->toRfc4122(),
                'counter' => $record->counter,
                'backup_eligible' => $record->backupEligible,
                'backup_status' => $record->backupStatus,
            ])->save();

            $this->twoFactor->refresh($user);
            $this->audit->record(action: 'identity.passkey_added', target: $passkey, actor: $user, new: ['name' => $name, 'rp_id' => $passkey->rp_id]);

            return $passkey;
        });
    }

    /**
     * @param  User|null  $user  Null: any passkey of this address (passwordless sign-in).
     * @return array<string, mixed> Options for navigator.credentials.get().
     */
    public function requestOptions(Request $request, string $purpose, ?User $user = null): array
    {
        $this->ensureAvailable($request);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $request->getHost(),
            allowCredentials: $user === null ? [] : $this->descriptors($user, $request->getHost()),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: $this->timeoutMs(),
        );

        return $this->remember($request, $purpose, $user, $options);
    }

    /**
     * Checks a passkey answer. Returns the person it belongs to.
     *
     * @param  array<string, mixed>  $credential
     * @param  User|null  $expected  When set, the passkey must be this person's.
     */
    public function verify(Request $request, string $purpose, array $credential, ?User $expected = null): User
    {
        $options = $this->take($request, $purpose, $expected, PublicKeyCredentialRequestOptions::class);
        $publicKeyCredential = $this->load($credential);
        $response = $publicKeyCredential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw TwoFactorException::passkeyFailed();
        }

        $passkey = Passkey::query()
            ->with('user')
            ->where('rp_id', $request->getHost())
            ->where('credential_hash', Passkey::hashId(self::encode($publicKeyCredential->rawId)))
            ->first();

        if ($passkey === null || ($expected !== null && $passkey->user_id !== $expected->getKey())) {
            throw TwoFactorException::passkeyFailed();
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies($request)->requestCeremony())
                ->check($this->record($passkey), $response, $options, $request->getHost(), $passkey->user_id);
        } catch (Throwable) {
            throw TwoFactorException::passkeyFailed();
        }

        $passkey->forceFill(['counter' => $record->counter, 'backup_status' => $record->backupStatus, 'last_used_at' => now()])->save();

        return $passkey->user;
    }

    public function rename(User $user, string $passkeyId, string $name): Passkey
    {
        $passkey = Passkey::query()->where('user_id', $user->getKey())->findOrFail($passkeyId);
        $passkey->forceFill(['name' => $name])->save();

        return $passkey;
    }

    public function remove(User $user, Passkey $passkey): void
    {
        $passkey->delete();
        $this->twoFactor->refresh($user);

        $this->audit->record(action: 'identity.passkey_removed', target: $passkey, actor: $user, old: ['name' => $passkey->name, 'rp_id' => $passkey->rp_id]);
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    private function ensureAvailable(Request $request): void
    {
        if (! $this->available($request)) {
            throw TwoFactorException::passkeyUnavailable();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function remember(Request $request, string $purpose, ?User $user, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $json = $this->serializer()->serialize($options, 'json');

        $request->session()->put(self::SESSION_KEY, [
            'purpose' => $purpose,
            'user_id' => $user?->getKey(),
            'options' => $json,
            'expires_at' => now()->addSeconds((int) config('identity.two_factor.passkey_timeout_seconds'))->getTimestamp(),
        ]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The ceremony started for this purpose (and person); used once.
     *
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function take(Request $request, string $purpose, ?User $user, string $class): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        $ceremony = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($ceremony)
            || $ceremony['purpose'] !== $purpose
            || $ceremony['user_id'] !== $user?->getKey()
            || $ceremony['expires_at'] < now()->getTimestamp()) {
            throw TwoFactorException::passkeyFailed();
        }

        return $this->serializer()->deserialize($ceremony['options'], $class, 'json');
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function load(array $credential): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');
        } catch (Throwable) {
            throw TwoFactorException::passkeyFailed();
        }
    }

    private function record(Passkey $passkey): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: self::decode($passkey->credential_id),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $passkey->transports ?? [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($passkey->aaguid ?? '00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: self::decode($passkey->public_key),
            userHandle: $passkey->user_id,
            counter: $passkey->counter,
            backupEligible: $passkey->backup_eligible,
            backupStatus: $passkey->backup_status,
        );
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(User $user, string $rpId): array
    {
        return Passkey::query()
            ->where('user_id', $user->getKey())
            ->where('rp_id', $rpId)
            ->get()
            ->map(fn (Passkey $passkey) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                self::decode($passkey->credential_id),
                $passkey->transports ?? [],
            ))
            ->values()
            ->all();
    }

    private function ceremonies(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        // Exactly this address: scheme, host and port.
        $factory->setAllowedOrigins([$request->getSchemeAndHttpHost()]);

        return $factory;
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport])
        ))->create();
    }

    private function brandName(): string
    {
        return $this->brands->for($this->host->partner(), $this->host->client())['name'];
    }

    private function timeoutMs(): int
    {
        return (int) config('identity.two_factor.passkey_timeout_seconds') * 1000;
    }
}
