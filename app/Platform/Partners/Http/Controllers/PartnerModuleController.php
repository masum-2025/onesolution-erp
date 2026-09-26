<?php

namespace App\Platform\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Http\Requests\SetPartnerModuleRequest;
use App\Platform\Partners\Models\PartnerModule;
use App\Platform\Partners\Services\PartnerModuleService;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: modules on / off / locked for all of the partner's clients.
 */
class PartnerModuleController extends Controller
{
    use PartnerConsole;

    public function __construct(private PartnerModuleService $modules, private ModuleRegistry $registry) {}

    public function index(): JsonResponse
    {
        $partner = $this->partner();
        $rows = PartnerModule::query()->where('partner_id', $partner->getKey())->get()->keyBy('module_key');
        $offered = $this->modules->offered($partner);

        $data = [];
        foreach ($this->registry->all() as $key => $module) {
            $row = $rows->get($key);
            $data[] = [
                'key' => $key,
                'name' => $module->label(),
                'description' => __($module->description),
                'category' => $module->category,
                'is_core' => $module->isCore,
                'requires' => $module->requires,
                // Whether the platform lets this partner offer the module at all.
                'offered' => $module->isCore || $offered === null || in_array($key, $offered, true),
                'state' => $row?->state->value ?? 'inherit',
                'locked' => (bool) $row?->locked,
            ];
        }

        return response()->json(['data' => $data, 'can_edit' => $this->hasRole(PartnerUserRole::Owner)]);
    }

    public function update(SetPartnerModuleRequest $request, string $module): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        abort_unless($this->registry->has($module), 404);

        $result = $this->modules->set(
            $this->partner(),
            $module,
            ModuleState::from($request->validated('state')),
            $request->boolean('lock'),
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json(['data' => $result, 'message' => __('partners.messages.module_saved')]);
    }
}
