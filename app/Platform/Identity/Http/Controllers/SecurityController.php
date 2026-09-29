<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Identity\Exceptions\TwoFactorException;
use App\Platform\Identity\Http\Requests\NewPasskeyRequest;
use App\Platform\Identity\Http\Requests\PasskeyNameRequest;
use App\Platform\Identity\Http\Requests\StepUpRequest;
use App\Platform\Identity\Http\Requests\TwoFactorCodeRequest;
use App\Platform\Identity\Models\Passkey;
use App\Platform\Identity\Services\PasskeyService;
use App\Platform\Identity\Services\StepUp;
use App\Platform\Identity\Services\TwoFactorRequirement;
use App\Platform\Identity\Services\TwoFactorService;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\TenancyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My account → Security (Phase 8-1): the authenticator app, recovery codes,
 * passkeys, whether the organization being worked in requires two-step
 * sign-in (and where that comes from), and confirming before a sensitive
 * action. Works in any context, also when a requirement blocks the
 * organization itself, so the person can always set it up.
 *
 * Adding or removing a second step needs a recent one (two_factor.recent).
 */
class SecurityController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private PasskeyService $passkeys,
        private TwoFactorRequirement $requirement,
        private StepUp $stepUp,
    ) {}

    public function show(Request $request, ContextSource $source, ContextResolver $resolver, CurrentContext $context): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactor(),
            'totp' => $this->twoFactor->hasTotp($user),
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($user),
            'passkeys_available' => $this->passkeys->available($request),
            'passkeys' => Passkey::query()
                ->where('user_id', $user->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (Passkey $passkey) => [
                    'id' => $passkey->getKey(),
                    'name' => $passkey->name,
                    // Passkeys belong to an address; ones made on another address do not work here.
                    'here' => $passkey->rp_id === $request->getHost(),
                    'address' => $passkey->rp_id,
                    'synced' => $passkey->backup_eligible,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ])
                ->values(),
            'requirement' => $this->requirementHere($request, $user, $source, $resolver, $context),
            'recently_confirmed' => $user->hasTwoFactor() && $this->stepUp->satisfied($request, $user),
        ]]);
    }

    public function startTotp(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->twoFactor->startTotp($request->user())]);
    }

    public function confirmTotp(TwoFactorCodeRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirmTotp($request->user(), (string) $request->validated('code'));
        $this->stepUp->markConfirmed($request);

        return response()->json(['data' => ['recovery_codes' => $codes], 'message' => __('two_factor.messages.totp_enabled')]);
    }

    public function disableTotp(Request $request, ContextSource $source, ContextResolver $resolver, CurrentContext $context): JsonResponse
    {
        $user = $request->user();
        $passkeysLeft = Passkey::query()->where('user_id', $user->getKey())->exists();
        $this->ensureNotLast($request, $user, $passkeysLeft, $source, $resolver, $context);

        $this->twoFactor->disableTotp($user);

        return response()->json(['message' => __('two_factor.messages.totp_disabled')]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        if (! $request->user()->hasTwoFactor()) {
            throw TwoFactorException::notStarted();
        }

        return response()->json([
            'data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user())],
            'message' => __('two_factor.messages.codes_regenerated'),
        ]);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->passkeys->creationOptions($request, $request->user())]);
    }

    public function storePasskey(NewPasskeyRequest $request): JsonResponse
    {
        $user = $request->user();
        $passkey = $this->passkeys->register($request, $user, $request->validated('name'), $request->validated('credential'));
        $codes = $this->twoFactor->codesForFirstStep($user);
        $this->stepUp->markConfirmed($request);

        return response()->json([
            'data' => ['id' => $passkey->getKey(), 'recovery_codes' => $codes],
            'message' => __('two_factor.messages.passkey_added'),
        ], 201);
    }

    public function renamePasskey(PasskeyNameRequest $request, string $passkey): JsonResponse
    {
        $this->passkeys->rename($request->user(), $passkey, $request->validated('name'));

        return response()->json(['message' => __('two_factor.messages.passkey_renamed')]);
    }

    public function destroyPasskey(Request $request, string $passkey, ContextSource $source, ContextResolver $resolver, CurrentContext $context): JsonResponse
    {
        $user = $request->user();
        $row = Passkey::query()->where('user_id', $user->getKey())->findOrFail($passkey);

        $othersLeft = $this->twoFactor->hasTotp($user) || Passkey::query()->where('user_id', $user->getKey())->whereKeyNot($row->getKey())->exists();
        $this->ensureNotLast($request, $user, $othersLeft, $source, $resolver, $context);

        $this->passkeys->remove($user, $row);

        return response()->json(['message' => __('two_factor.messages.passkey_removed')]);
    }

    /** Passkey options for confirming a sensitive action. */
    public function confirmOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->passkeys->requestOptions($request, 'confirm', $request->user())]);
    }

    public function confirm(StepUpRequest $request): JsonResponse
    {
        $this->stepUp->confirm(
            $request,
            $request->user(),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request->validated('credential'),
        );

        return response()->json(['message' => __('two_factor.messages.confirmed')]);
    }

    /** The last second step cannot go while the organization being worked in requires one. */
    private function ensureNotLast(Request $request, User $user, bool $othersLeft, ContextSource $source, ContextResolver $resolver, CurrentContext $context): void
    {
        if (! $othersLeft && ($this->requirementHere($request, $user, $source, $resolver, $context)['required'] ?? false)) {
            throw TwoFactorException::stillRequired();
        }
    }

    /**
     * The requirement of the context this session works in (entered without the
     * requirement check itself, so it can be shown while it blocks).
     *
     * @return array<string, mixed>|null
     */
    private function requirementHere(Request $request, User $user, ContextSource $source, ContextResolver $resolver, CurrentContext $context): ?array
    {
        try {
            if (($organizationId = $source->organizationId($request)) !== null) {
                $resolver->enterOrganization($user, $organizationId, checkSignIn: false);
            } elseif (($partnerId = $source->partnerId($request)) !== null) {
                $resolver->enterPartner($user, $partnerId, checkSignIn: false);
            } else {
                return null;
            }
        } catch (TenancyException) {
            return null;
        }

        return $this->requirement->describe($user, $context);
    }
}
