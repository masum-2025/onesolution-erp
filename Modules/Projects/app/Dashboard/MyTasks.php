<?php

namespace Modules\Projects\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Services\Tasks;
use Modules\Projects\Services\Workplace;

/** The signed-in person's next tasks: overdue first, then the soonest due. */
final class MyTasks implements DashboardWidget
{
    use ReadsProjects;

    private const SHOWN = 6;

    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        $tasks = app(Tasks::class)->mine($unit, $context->user())->take(self::SHOWN);
        $place = app(Workplace::class);
        $projects = $place->query(Project::class, $unit, $place->subtreeIds($unit))->whereKey($tasks->pluck('project_id')->unique()->all())->get()->keyBy('id');
        $today = $this->todayAt($context);

        return WidgetData::list($tasks->map(fn (Task $task) => [
            'label' => $task->title,
            'meta' => $projects[$task->project_id]?->code.' · '.$projects[$task->project_id]?->client_name,
            // Y-m-d: the browser writes it in the reader's digits.
            'date' => $task->due_on?->toDateString(),
            'path' => "/projects/{$task->project_id}?task={$task->getKey()}",
            'tone' => $task->due_on !== null && $task->due_on->lt($today) ? 'bad' : ($task->status === Task::BLOCKED ? 'warn' : null),
        ])->values()->all());
    }
}
