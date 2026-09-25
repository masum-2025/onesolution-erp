<?php

namespace App\Platform\Modules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Http\Requests\PurgeModuleRequest;
use App\Platform\Modules\Http\Requests\ReasonRequest;
use App\Platform\Modules\Models\ModulePurgeRequest;
use App\Platform\Modules\Services\ModulePurgeService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ModulePurgeController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private ModulePurgeService $purges) {}

    public function store(PurgeModuleRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $purge = $this->purges->request(
            $organization,
            $module,
            $request->validated('confirm_text'),
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json([
            'data' => $this->present($purge),
            'message' => __('modules.messages.purge_scheduled', [
                'date' => $purge->execute_after->toDateString(),
            ]),
        ], 201);
    }

    public function destroy(ReasonRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $purge = $this->purges->cancel($organization, $module, $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->present($purge)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ModulePurgeRequest $purge): array
    {
        return [
            'id' => $purge->id,
            'module_key' => $purge->module_key,
            'status' => $purge->status->value,
            'execute_after' => $purge->execute_after->toIso8601String(),
        ];
    }
}
