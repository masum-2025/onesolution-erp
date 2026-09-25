<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks credentials. The same error and similar timing whether the email
 * exists or not, so accounts cannot be discovered through login.
 */
class AttemptLogin
{
    private static ?string $dummyHash = null;

    public function __construct(private AuditLogger $audit) {}

    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        // Check against a real hash even for unknown emails to keep timing similar.
        $hash = $user?->getAuthPassword() ?? (self::$dummyHash ??= Hash::make('timing-equaliser'));
        $valid = Hash::check($password, $hash) && $user !== null;

        if (! $valid) {
            $this->audit->record(action: 'auth.login_failed', actor: $user);

            throw ValidationException::withMessages([
                'email' => __('tenancy.errors.credentials'),
            ]);
        }

        $this->audit->record(action: 'auth.login', actor: $user);

        return $user;
    }
}
