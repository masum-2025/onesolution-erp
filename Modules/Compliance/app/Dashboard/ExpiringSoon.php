<?php

namespace Modules\Compliance\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Modules\Compliance\Support\Validity;
use NumberFormatter;

/** The most urgent audits: expired first, then those expiring soonest. */
final class ExpiringSoon implements DashboardWidget
{
    use ReadsRegister;

    private const SHOWN = 6;

    public function data(CurrentContext $context): array
    {
        $due = $this->standing($context)->whereIn('state', [Validity::EXPIRED, Validity::EXPIRING])->sortBy('days_left')->take(self::SHOWN);
        $factories = Organization::query()->whereKey($due->map(fn (array $line) => $line['audit']->organization_id)->unique()->all())->get()->keyBy(fn ($unit) => $unit->getKey());
        $digits = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);

        return WidgetData::list($due->map(fn (array $line) => [
            'label' => $line['audit']->service?->textIn('name') ?? '—',
            'meta' => ($factories[$line['audit']->organization_id]?->displayName() ?? '').' · '.($line['state'] === Validity::EXPIRED
                ? __('compliance::dashboard.expired')
                // The count in the reader's digits (৪ in Bangla).
                : trans_choice('compliance::dashboard.expires_in', $line['days_left'], ['count' => $digits->format($line['days_left'])])),
            'date' => $line['audit']->expires_on?->toDateString(),
            'path' => "/compliance?audit={$line['audit']->getKey()}",
            'tone' => $line['state'] === Validity::EXPIRED ? 'bad' : 'warn',
        ])->values()->all());
    }
}
