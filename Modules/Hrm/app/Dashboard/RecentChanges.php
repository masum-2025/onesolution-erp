<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmploymentEvent;

/** The latest employment changes (hired, promoted, left, ...), newest first. */
final class RecentChanges implements DashboardWidget
{
    use ReadsEmployees;

    private const SHOWN = 6;

    // How each change reads at a glance; the words say it too.
    private const TONES = [
        'hired' => 'good', 'rehired' => 'good', 'confirmed' => 'good', 'promoted' => 'good',
        'notice_given' => 'warn', 'exited' => 'bad',
    ];

    public function data(CurrentContext $context): array
    {
        $events = EmploymentEvent::query()
            ->whereIn('organization_id', $this->unitIds($context))
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->limit(self::SHOWN)
            ->get();

        $names = Employee::query()->whereKey($events->pluck('employee_id')->unique()->values()->all())->pluck('full_name', 'id');

        return WidgetData::list($events->map(fn (EmploymentEvent $event) => [
            'label' => $names[$event->employee_id] ?? '—',
            'meta' => __('hrm::hrm.events.'.$event->type->value),
            'date' => $event->effective_on->toDateString(),
            'path' => isset($names[$event->employee_id]) ? "/hrm/employees/{$event->employee_id}" : null,
            'tone' => self::TONES[$event->type->value] ?? null,
        ])->all());
    }
}
