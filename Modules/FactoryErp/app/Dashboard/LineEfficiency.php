<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Number;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Services\Efficiency;
use Modules\FactoryErp\Services\Floor;

/**
 * Each sewing line's efficiency today (whole per cent), for lines whose
 * day (who worked, how long) is written; the target in the hint.
 */
final class LineEfficiency implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::bars([]);
        }
        $floor = app(Floor::class);
        $company = $place[0];
        $lines = array_filter(app(Efficiency::class)->ofDay($company, $context->organization(), $floor->today($company)), fn (array $line) => $line['efficiency_bp'] !== null);
        $codes = $floor->query(Line::class, $company)->whereKey(array_keys($lines))->pluck('code', 'id');
        $target = (int) app(RuleResolver::class)->get('factory_erp.target_efficiency_bp', app(RuleContextFactory::class)->forOrganization($context->organization()));

        return WidgetData::bars(collect($lines)->map(fn (array $line, string $id) => [
            'label' => $codes[$id] ?? '—',
            'value' => intdiv($line['efficiency_bp'], 100),
        ])->values()->all(), __('factory_erp::dashboard.efficiency_hint', ['target' => Number::format(intdiv($target, 100), locale: app()->getLocale())]));
    }
}
