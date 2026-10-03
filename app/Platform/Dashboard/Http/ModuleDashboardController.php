<?php

namespace App\Platform\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleDefinition;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Module dashboards (every module has one) and the overview that gathers the
 * widgets modules mark for it. The list says which widgets the person may
 * see; each widget's data is a separate call, so one slow or failing widget
 * never blocks the others.
 */
class ModuleDashboardController extends Controller
{
    public function __construct(
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
        private CurrentContext $context,
    ) {}

    public function index(string $module): JsonResponse
    {
        $definition = $this->enabledModule($module);

        return response()->json([
            'module' => ['key' => $definition->key, 'name' => $definition->label(), 'description' => __($definition->description)],
            'data' => array_map(fn (array $widget) => $this->present($definition, $widget), $this->visibleWidgets($definition)),
        ]);
    }

    public function show(string $module, string $widget): JsonResponse
    {
        $definition = $this->enabledModule($module);
        $found = $definition->widget($widget);

        abort_if($found === null, 404);
        abort_unless(Gate::allows($found['permission'], $this->context->organization()), 403);

        $provider = app($found['provider']);
        if (! $provider instanceof DashboardWidget) {
            throw new InvalidArgumentException("[{$found['provider']}] is not a DashboardWidget.");
        }

        return response()->json(['data' => $provider->data($this->context)]);
    }

    /** Widgets of every module that is on here and marks them for the overview. */
    public function overview(): JsonResponse
    {
        $resolved = $this->resolver->resolveAll($this->context->organization());
        $widgets = [];

        foreach ($this->modules->all() as $key => $definition) {
            if (! $resolved[$key]->enabled) {
                continue;
            }
            foreach ($this->visibleWidgets($definition) as $widget) {
                if ($widget['overview'] ?? false) {
                    $widgets[] = $this->present($definition, $widget);
                }
            }
        }

        return response()->json(['data' => $widgets]);
    }

    private function enabledModule(string $key): ModuleDefinition
    {
        $definition = $this->modules->all()[$key] ?? null;
        abort_if($definition === null, 404);

        if (! $this->resolver->resolveAll($this->context->organization())[$key]->enabled) {
            throw ModuleException::disabled($definition->label());
        }

        return $definition;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visibleWidgets(ModuleDefinition $definition): array
    {
        $organization = $this->context->organization();

        return array_values(array_filter($definition->widgets, fn (array $widget) => Gate::allows($widget['permission'], $organization)));
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function present(ModuleDefinition $definition, array $widget): array
    {
        return [
            'module' => $definition->key,
            'module_name' => $definition->label(),
            'key' => $widget['key'],
            'label' => __($widget['label']),
            'type' => $widget['type'],
            'size' => $widget['size'] ?? 1,
        ];
    }
}
