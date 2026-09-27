<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Actions\UpgradeWorkspace;
use App\Platform\Identity\Http\Requests\UpgradeRequest;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Upgrading a personal workspace to a company (Phase 5C-3). Only its owner,
 * inside it; the workspace always comes from the context.
 */
class UpgradeController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private UpgradeWorkspace $upgrade) {}

    public function preview(Request $request, string $organization): JsonResponse
    {
        return response()->json(['data' => $this->upgrade->preview($this->findVisible($organization)->loadMissing('partner'), $request->user())]);
    }

    public function store(UpgradeRequest $request, string $organization): JsonResponse
    {
        $company = $this->upgrade->handle($this->findVisible($organization)->loadMissing('partner'), $request->user(), [
            'name' => $request->names(),
            'sector_key' => $request->validated('sector_key'),
            'plan_key' => $request->validated('plan_key'),
            'period' => $request->validated('period'),
        ]);

        return response()->json([
            'data' => ['id' => $company->getKey(), 'type' => $company->type->value, 'name' => $company->displayName()],
            'message' => __('identity.messages.upgraded'),
        ]);
    }
}
