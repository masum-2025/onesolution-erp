<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Context\ContextSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Every way into the browser app ends here (password, sign-up, invitation,
 * password reset, passkey): a person with two-step sign-in first gets the
 * second step (Phase 8-1); everyone else is signed in at once, with a new
 * session id and no organization chosen yet.
 */
class SessionSignIn
{
    public function __construct(
        private TwoFactorLogin $twoFactor,
        private StepUp $stepUp,
        private ListAvailableContexts $contexts,
        private ContextSource $source,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $extra  Response fields for a finished sign-in (e.g. a message).
     */
    public function start(Request $request, User $user, array $extra = []): JsonResponse
    {
        if ($user->hasTwoFactor()) {
            return response()->json(['two_factor' => $this->twoFactor->begin($request, $user)]);
        }

        return $this->finish($request, $user, null, $extra);
    }

    /**
     * @param  string|null  $method  The second step used, if any (it also counts as a fresh step-up).
     * @param  array<string, mixed>  $extra
     */
    public function finish(Request $request, User $user, ?string $method = null, array $extra = []): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->source->forgetSession($request);

        if ($method !== null) {
            $this->stepUp->markConfirmed($request);
            $this->audit->record(action: 'auth.login', actor: $user, new: ['second_step' => $method]);
        }

        return response()->json(['contexts' => $this->contexts->handle($user), ...$extra]);
    }
}
