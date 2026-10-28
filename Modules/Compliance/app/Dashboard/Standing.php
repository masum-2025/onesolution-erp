<?php

namespace Modules\Compliance\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Compliance\Support\Validity;

/** How the register stands: valid, expiring, expired, no expiry day known. */
final class Standing implements DashboardWidget
{
    use ReadsRegister;

    public function data(CurrentContext $context): array
    {
        $states = $this->standing($context)->countBy('state');

        return WidgetData::bars(array_values(array_filter(array_map(fn (string $state) => [
            'label' => __("compliance::compliance.states.{$state}"),
            'value' => (int) ($states[$state] ?? 0),
        ], Validity::STATES), fn (array $item) => $item['value'] > 0)));
    }
}
