<?php

namespace App\Platform\Identity\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Two-step sign-in (Phase 8-1). Messages never say whether an account
 * exists or which step failed beyond what the person already knows.
 */
class TwoFactorException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'two_factor.errors.'.$this->errorCode;
    }

    public static function invalidCode(string $field = 'code'): self
    {
        return new self('invalid_code', 422, [], ['field' => $field]);
    }

    /** The sign-in waiting for its second step ended (time or attempts): start again. */
    public static function challengeEnded(): self
    {
        return new self('challenge_ended', 422);
    }

    public static function passkeyFailed(): self
    {
        return new self('passkey_failed', 422);
    }

    /** Passkeys need HTTPS (or a local development address). */
    public static function passkeyUnavailable(): self
    {
        return new self('passkey_unavailable', 422);
    }

    public static function alreadyEnabled(): self
    {
        return new self('already_enabled', 409);
    }

    public static function notStarted(): self
    {
        return new self('not_started', 409);
    }

    /** Removing the last second step while an organization requires one. */
    public static function stillRequired(): self
    {
        return new self('still_required', 409);
    }

    /**
     * A sensitive action needs the second step again (identity.step_up_minutes).
     *
     * @param  list<string>  $methods
     */
    public static function stepUpRequired(array $methods): self
    {
        return new self('step_up_required', 403, [], ['methods' => $methods]);
    }

    public static function resetSelf(): self
    {
        return new self('reset_self', 422);
    }

    public static function resetSamePerson(): self
    {
        return new self('reset_same_person', 403);
    }

    /** The person also works for other accounts: only they (recovery code) or the platform may reset. */
    public static function resetElsewhere(): self
    {
        return new self('reset_elsewhere', 422);
    }

    public static function resetNothing(): self
    {
        return new self('reset_nothing', 422);
    }

    public static function resetPending(): self
    {
        return new self('reset_pending', 409);
    }

    public static function resetNotOpen(): self
    {
        return new self('reset_not_open', 409);
    }
}
