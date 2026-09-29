<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks credentials: an email, or a verified phone number (Phase 5C). The
 * same error and similar timing whether the account exists or not, so
 * accounts cannot be discovered through login.
 */
class AttemptLogin
{
    private static ?string $dummyHash = null;

    public function __construct(private AuditLogger $audit) {}

    public function handle(?string $email, string $password, ?string $phone = null, ?string $country = null): User
    {
        $field = $email !== null && $email !== '' ? 'email' : 'phone';
        $user = $field === 'email'
            ? User::query()->where('email', $email)->first()
            : $this->byPhone((string) $phone, $country);

        // Check against a real hash even for unknown accounts to keep timing similar.
        $hash = $user?->getAuthPassword() ?? (self::$dummyHash ??= Hash::make('timing-equaliser'));
        $valid = Hash::check($password, $hash) && $user !== null;

        if (! $valid) {
            $this->audit->record(action: 'auth.login_failed', actor: $user);

            throw ValidationException::withMessages([
                $field => __($field === 'email' ? 'tenancy.errors.credentials' : 'tenancy.errors.credentials_phone'),
            ]);
        }

        // With two-step sign-in the login is recorded once the second step is done (Phase 8-1).
        $this->audit->record(action: $user->hasTwoFactor() ? 'auth.password_accepted' : 'auth.login', actor: $user);

        return $user;
    }

    private function byPhone(string $phone, ?string $country): ?User
    {
        $e164 = PhoneNumber::normalize($phone, $country ?? config('tenancy.defaults.country_code'));

        // Only a verified phone signs in.
        return $e164 === null ? null : User::query()->where('phone', $e164)->whereNotNull('phone_verified_at')->first();
    }
}
