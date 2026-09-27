<?php

namespace App\Platform\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Http\Controllers\SignupController;
use App\Platform\Identity\Http\Requests\CodeRequest;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Portal\Http\Requests\PortalJoinRequest;
use App\Platform\Portal\Http\Requests\PortalSignupRequest;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\Services\PortalInvitations;
use App\Platform\Portal\Services\PortalJoin;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Context\ContextSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining a client's portal with an invitation (Phase 5C-4): see what it is
 * for, then join with an account that verified the invited address, or
 * create one with a code sent there. Browser session routes (CSRF).
 */
class PortalJoinController extends Controller
{
    public function __construct(private PortalInvitations $invitations, private PortalJoin $join) {}

    public function show(string $key): JsonResponse
    {
        return response()->json(['data' => $this->invitations->preview($this->invitations->find($key))]);
    }

    public function join(PortalJoinRequest $request): JsonResponse
    {
        $link = $this->join->join($request->user(), $this->invitations->find($request->validated('key')));

        return response()->json(['data' => $this->joined($link), 'message' => $this->message($link)]);
    }

    public function signup(PortalSignupRequest $request, OtpService $otp): JsonResponse
    {
        $challenge = $this->join->startSignup(
            $this->invitations->find($request->validated('key')),
            $request->validated('name'),
            $request->validated('password'),
            $request->ip(),
            app()->getLocale(),
        );
        $data = $otp->present($challenge);

        return response()->json(['data' => $data, 'message' => __('identity.messages.code_sent', ['to' => $data['to']])], 202);
    }

    public function verify(CodeRequest $request, ListAvailableContexts $contexts, ContextSource $source): JsonResponse
    {
        ['user' => $user, 'link' => $link] = $this->join->completeSignup($request->validated('challenge_id'), $request->validated('code'));

        $response = SignupController::signIn($request, $user, $contexts, $source, $this->message($link));
        $response->setData([...$response->getData(true), 'data' => $this->joined($link)]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function joined(PortalLink $link): array
    {
        return ['organization_id' => $link->organization_id, 'status' => $link->status];
    }

    private function message(PortalLink $link): string
    {
        return $link->isActive() ? __('portal.messages.joined') : __('portal.messages.joined_pending');
    }
}
