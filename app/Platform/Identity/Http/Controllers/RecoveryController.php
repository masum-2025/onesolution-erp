<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Http\Requests\RecoveryCompleteRequest;
use App\Platform\Identity\Http\Requests\RecoveryRequest;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Identity\Services\RecoveryService;
use Illuminate\Http\JsonResponse;

/**
 * "Forgot password": a code to the account's email or phone, then a new
 * password, and the person is signed in.
 */
class RecoveryController extends Controller
{
    public function __construct(private RecoveryService $recovery, private OtpService $otp) {}

    public function start(RecoveryRequest $request): JsonResponse
    {
        $data = $this->otp->present($this->recovery->start($request->validated(), $request->ip()));

        return response()->json(['data' => $data, 'message' => __('identity.messages.code_sent', ['to' => $data['to']])], 202);
    }

    public function complete(RecoveryCompleteRequest $request): JsonResponse
    {
        $user = $this->recovery->complete($request->validated('challenge_id'), $request->validated('code'), $request->validated('password'));

        return SignupController::signIn($request, $user, __('identity.messages.password_reset'));
    }
}
