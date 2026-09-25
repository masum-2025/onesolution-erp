<?php

namespace App\Platform\Modules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Http\Requests\GrantConsentRequest;
use App\Platform\Modules\Http\Requests\ReasonRequest;
use App\Platform\Modules\Services\ModuleConsentService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ModuleConsentController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private ModuleConsentService $consents) {}

    public function store(GrantConsentRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $consent = $this->consents->grant(
            $organization,
            $module,
            $request->validated('terms_version'),
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json(['data' => [
            'id' => $consent->id,
            'module_key' => $consent->module_key,
            'terms_version' => $consent->terms_version,
            'granted_at' => $consent->granted_at->toIso8601String(),
        ]], 201);
    }

    public function destroy(ReasonRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $this->consents->revoke($organization, $module, $request->validated('reason'), $request->user());

        return response()->json(['message' => __('modules.messages.consent_revoked')]);
    }
}
