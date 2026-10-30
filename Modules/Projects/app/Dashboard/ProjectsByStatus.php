<?php

namespace Modules\Projects\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\Workplace;

/** Projects at the unit and below by status (open ones; done and cancelled are history). */
final class ProjectsByStatus implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = app(Workplace::class);
        $unit = $context->organization();
        $counts = $place->query(Project::class, $unit, $place->subtreeIds($unit))->whereIn('status', Project::OPEN)->pluck('status')->countBy();

        return WidgetData::bars(array_values(array_filter(array_map(fn (string $status) => [
            'label' => __("projects::projects.statuses.{$status}"),
            'value' => (int) ($counts[$status] ?? 0),
        ], Project::OPEN), fn (array $item) => $item['value'] > 0)));
    }
}
