<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Http\Requests\PasskeyRequest;
use App\Platform\Identity\Http\Requests\TwoFactorCodeRequest;
use App\Platform\Identity\Services\PasskeyService;
use App\Platform\Identity\Services\SessionSignIn;
use App\Platform\Identity\Services\TwoFactorLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browser sign-in, second step and passkeys (Phase 8-1):
 *
 *  - POST /session/two-factor: an app or recovery code for the waiting sign-in;
 *  - POST /session/passkey/options + /session/passkey: a passkey, either for
 *    the waiting sign-in (that person's passkeys only) or on its own, with
 *    no password at all (any passkey made for this address).
 */
class TwoFactorSessionController extends Controller
{
    public function __construct(
        private TwoFactorLogin $login,
        private PasskeyService $passkeys,
        private SessionSignIn $signIn,
    ) {}

    public function code(TwoFactorCodeRequest $request): JsonResponse
    {
        [$user, $method] = $this->login->completeWithCode($request, $request->validated('code'), $request->validated('recovery_code'));

        return $this->signIn->finish($request, $user, $method);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $options = $this->login->isPending($request)
            ? $this->login->passkeyOptions($request)
            : $this->passkeys->requestOptions($request, 'login');

        return response()->json(['data' => $options]);
    }

    public function passkey(PasskeyRequest $request): JsonResponse
    {
        $credential = $request->validated('credential');

        $user = $this->login->isPending($request)
            ? $this->login->completeWithPasskey($request, $credential)
            : $this->passkeys->verify($request, 'login', $credential);

        return $this->signIn->finish($request, $user, 'passkey');
    }
}
