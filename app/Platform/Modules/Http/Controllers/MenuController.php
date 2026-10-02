<?php

namespace App\Platform\Modules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Navigation for the current organization: only enabled modules the person
 * holds at least one permission of appear, grouped in sections, with the
 * sub-pages and "New" actions their permissions allow. (Hiding a menu entry
 * is never the protection: routes use module:{key} and permission checks.)
 */
class MenuController extends Controller
{
    public function __invoke(CurrentContext $context, ModuleRegistry $registry, ModuleResolver $resolver, AccessResolver $access): JsonResponse
    {
        $resolved = $resolver->resolveAll($context->organization());
        $held = $access->held();
        $allows = fn (array $entry) => ! isset($entry['permission']) || in_array($entry['permission'], $held, true);
        // Read-only and export-only organizations create nothing.
        $canCreate = $context->mode() === CurrentContext::MODE_NORMAL;
        $items = [];
        $actions = [];

        foreach ($registry->all() as $key => $module) {
            if (! $resolved[$key]->enabled || array_intersect($module->permissions, $held) === []) {
                continue;
            }

            foreach ($module->menu as $item) {
                if (! $allows($item)) {
                    continue;
                }

                $section = $item['section'] ?? $module->category;
                $items[] = [
                    'module' => $key,
                    'key' => $item['key'],
                    'label' => __($item['label']),
                    'route' => $item['route'],
                    'icon' => $item['icon'] ?? null,
                    'order' => $item['order'],
                    'section' => $section,
                    'section_label' => $this->sectionLabel($section),
                    'children' => array_values(array_map(fn (array $child) => [
                        'key' => $child['key'],
                        'label' => __($child['label']),
                        'route' => $child['route'],
                    ], array_filter($item['children'] ?? [], $allows))),
                ];
            }

            foreach ($canCreate ? $module->quickActions : [] as $action) {
                if ($allows($action)) {
                    $actions[] = [
                        'module' => $key,
                        'key' => $action['key'],
                        'label' => __($action['label']),
                        'route' => $action['route'],
                        'icon' => $action['icon'] ?? null,
                    ];
                }
            }
        }

        usort($items, fn (array $a, array $b) => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return response()->json(['data' => $items, 'quick_actions' => $actions]);
    }

    private function sectionLabel(string $section): string
    {
        return Lang::has("modules.sections.{$section}") ? __("modules.sections.{$section}") : Str::headline($section);
    }
}
