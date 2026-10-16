<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Services\Floor;

/**
 * Good pieces each sewing line made today, largest first, with its reject rate.
 */
final class LineOutput implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::bars([]);
        }
        $made = TodayOutput::byLine(...$place);
        $codes = app(Floor::class)->query(Line::class, $place[0])->whereKey(array_keys($made))->pluck('code', 'id');

        return WidgetData::bars(collect($made)->map(fn (array $line, string $id) => [
            'label' => __('factory_erp::dashboard.line_bar', ['line' => $codes[$id] ?? '—', 'rate' => TodayOutput::rateText($line['good'], $line['rejects'])]),
            'value' => $line['good'],
        ])->values()->all());
    }
}
