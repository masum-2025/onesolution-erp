<?php

namespace App\Platform\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A module's settings page: its own setting screens (manifest
 * `settings.pages`, filtered by permission) and how many rules it has. The
 * rules themselves come from the rules API (?module=), which shows where each
 * value comes from and who may change it.
 */
class ModuleSettingsController extends Controller
{
    public function __invoke(string $module, ModuleRegistry $modules, ModuleResolver $resolver, CurrentContext $context): JsonResponse
    {
        $definition = $modules->all()[$module] ?? null;
        abort_if($definition === null, 404);

        $organization = $context->organization();
        $resolved = $resolver->resolveAll($organization)[$module];
        if (! $resolved->enabled) {
            throw ModuleException::disabled($definition->label());
        }

        $pages = array_filter($definition->settingsPages, fn (array $page) => ! isset($page['permission']) || Gate::allows($page['permission'], $organization));

        return response()->json(['data' => [
            'module' => ['key' => $definition->key, 'name' => $definition->label(), 'description' => __($definition->description)],
            'pages' => array_values(array_map(fn (array $page) => ['key' => $page['key'], 'label' => __($page['label']), 'route' => $page['route']], $pages)),
            'rule_count' => count($definition->rules),
            'can_manage_modules' => Gate::allows('modules.manage', $organization),
        ]]);
    }
}
