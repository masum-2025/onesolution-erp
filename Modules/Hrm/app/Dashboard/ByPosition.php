<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Models\Position;

/** Employed people per position: the six largest, the rest together. */
final class ByPosition implements DashboardWidget
{
    use ReadsEmployees;

    private const SHOWN = 6;

    public function data(CurrentContext $context): array
    {
        // Counted here rather than in SQL: no raw SQL, and the same on MySQL and PostgreSQL.
        $counts = $this->employees($context)
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->pluck('position_id')
            ->countBy(fn ($id) => $id ?? '')
            ->sortDesc();

        $titles = Position::query()
            ->whereKey($counts->keys()->filter()->values()->all())
            ->get()
            ->mapWithKeys(fn (Position $position) => [$position->getKey() => $position->title]);

        $items = $counts->take(self::SHOWN)->map(fn (int $people, $id) => [
            'label' => $titles[$id] ?? __('hrm::dashboard.no_position'),
            'value' => $people,
        ])->values()->all();

        if ($counts->count() > self::SHOWN) {
            $items[] = ['label' => __('hrm::dashboard.other_positions'), 'value' => $counts->skip(self::SHOWN)->sum()];
        }

        return WidgetData::bars($items);
    }
}
