<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Modules\FactoryErp\Services\Quality;

/**
 * Defects found at end-line checks today at the unit, with the DHU and
 * pieces checked; in the bell, lines whose DHU today reaches the rule
 * (factory_erp.dhu_warn_bp).
 */
final class QualityToday implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        $lines = app(Quality::class)->todayByLine(...$place);
        $checked = array_sum(array_column($lines, 'checked'));
        $defects = array_sum(array_column($lines, 'defects'));

        return WidgetData::stat($defects, hint: __('factory_erp::dashboard.quality_hint', [
            'dhu' => self::dhuText(Quality::dhuBp($defects, $checked) ?? 0), 'checked' => Number::format($checked, locale: app()->getLocale()),
        ]));
    }

    public function items(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null || ! Gate::allows('factory_erp.qc', $context->organization())) {
            return [];
        }
        $warn = (int) app(RuleResolver::class)->get('factory_erp.dhu_warn_bp', app(RuleContextFactory::class)->forOrganization($context->organization()));
        $high = array_filter(app(Quality::class)->todayByLine(...$place), fn (array $line) => (Quality::dhuBp($line['defects'], $line['checked']) ?? 0) >= $warn);

        return $high === [] ? [] : [new AttentionItem('factory_erp.dhu_high', __('factory_erp::dashboard.attention_dhu'), count($high), '/factory/qc', 'bad')];
    }

    /** DHU (basis points of a unit) as "3.2" in the reader's digits. */
    public static function dhuText(int $dhuBp): string
    {
        return Number::format(intdiv($dhuBp + 5, 10) / 10, 1, locale: app()->getLocale());
    }
}
