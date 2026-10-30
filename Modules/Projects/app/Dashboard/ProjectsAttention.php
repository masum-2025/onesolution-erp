<?php

namespace Modules\Projects\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Models\Task;
use Modules\Projects\Services\Inbox;
use Modules\Projects\Services\Tasks;

/** In the bell: the person's own tasks past their due day (or blocked), at the signed-in unit and below. */
final class ProjectsAttention implements AttentionProvider
{
    use ReadsProjects;

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || (! Gate::allows('projects.work', $unit) && ! Gate::allows('projects.manage', $unit))) {
            return [];
        }
        $items = [];
        // Connected clients' renewal requests waiting for an answer (managers).
        if (Gate::allows('projects.manage', $unit)) {
            $waiting = app(Inbox::class)->list($unit, newOnly: true)->count();
            if ($waiting > 0) {
                $items[] = new AttentionItem('projects.requests', __('projects::dashboard.attention_requests'), $waiting, '/projects?requests=1', 'warn');
            }
        }
        $today = $this->todayAt($context);
        $mine = app(Tasks::class)->mine($unit, $context->user());
        $overdue = $mine->filter(fn (Task $task) => $task->due_on !== null && $task->due_on->lt($today))->count();
        $blocked = $mine->where('status', Task::BLOCKED)->count();
        if ($overdue > 0) {
            $items[] = new AttentionItem('projects.overdue', __('projects::dashboard.attention_overdue'), $overdue, '/projects/my-tasks', 'bad');
        }
        if ($blocked > 0) {
            $items[] = new AttentionItem('projects.blocked', __('projects::dashboard.attention_blocked'), $blocked, '/projects/my-tasks', 'warn');
        }

        return $items;
    }
}
