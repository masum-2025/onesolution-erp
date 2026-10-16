<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Modules\FactoryErp\Models\Output;
use Modules\FactoryErp\Services\Floor;

/**
 * Good pieces sewn today at the unit, with the reject rate; in the bell,
 * lines whose rejects today reach the rule's warning rate
 * (factory_erp.reject_warn_bp, basis points of what they made).
 */
final class TodayOutput implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$good, $rejects] = array_reduce(self::byLine(...$place), fn (array $sum, array $line) => [$sum[0] + $line['good'], $sum[1] + $line['rejects']], [0, 0]);

        return WidgetData::stat($good, hint: __('factory_erp::dashboard.today_hint', ['rate' => self::rateText($good, $rejects)]));
    }

    public function items(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null || ! Gate::allows('factory_erp.produce', $context->organization())) {
            return [];
        }
        $warnBp = (int) app(RuleResolver::class)->get('factory_erp.reject_warn_bp', app(RuleContextFactory::class)->forOrganization($context->organization()));
        $high = array_filter(self::byLine(...$place), fn (array $line) => $line['good'] + $line['rejects'] > 0 && $warnBp * ($line['good'] + $line['rejects']) <= $line['rejects'] * 10000);

        return $high === [] ? [] : [new AttentionItem('factory_erp.rejects_high', __('factory_erp::dashboard.attention_rejects'), count($high), '/factory/output', 'bad')];
    }

    /**
     * Today's good pieces and rejects per line (entries not voided).
     *
     * @param  list<string>  $units
     * @return array<string, array{good: int, rejects: int}>
     */
    public static function byLine(Organization $company, array $units): array
    {
        $floor = app(Floor::class);

        return $floor->query(Output::class, $company)->whereIn('unit_id', $units)->where('worked_on', $floor->today($company)->toDateString())->whereNull('voided_at')
            ->get(['line_id', 'good', 'rejects'])->groupBy('line_id')
            ->map(fn ($entries) => ['good' => (int) $entries->sum('good'), 'rejects' => (int) $entries->sum('rejects')])->all();
    }

    /** Rejects as a share of all made, one decimal, in the reader's digits ("২.৫"). */
    public static function rateText(int $good, int $rejects): string
    {
        $all = $good + $rejects;
        $tenths = $all === 0 ? 0 : intdiv($rejects * 1000 + intdiv($all, 2), $all);

        return Number::format($tenths / 10, 1, locale: app()->getLocale());
    }
}
