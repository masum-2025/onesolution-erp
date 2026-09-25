<?php

namespace App\Platform\Modules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;

/**
 * Navigation for the current organization: only enabled modules the person
 * holds at least one permission of appear. (Hiding a menu entry is never the
 * protection: routes use module:{key} and permission checks.)
 */
class MenuController extends Controller
{
    public function __invoke(CurrentContext $context, ModuleRegistry $registry, ModuleResolver $resolver, AccessResolver $access): JsonResponse
    {
        $resolved = $resolver->resolveAll($context->organization());
        $held = $access->held();
        $items = [];

        foreach ($registry->all() as $key => $module) {
            if (! $resolved[$key]->enabled || array_intersect($module->permissions, $held) === []) {
                continue;
            }

            foreach ($module->menu as $item) {
                $items[] = [
                    'module' => $key,
                    'key' => $item['key'],
                    'label' => __($item['label']),
                    'route' => $item['route'],
                    'icon' => $item['icon'] ?? null,
                    'order' => $item['order'],
                ];
            }
        }

        usort($items, fn (array $a, array $b) => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return response()->json(['data' => $items]);
    }
}
