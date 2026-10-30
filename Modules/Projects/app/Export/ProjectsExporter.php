<?php

namespace Modules\Projects\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Models\TaskAssignee;
use Modules\Projects\Models\TaskNote;
use Modules\Projects\Models\Template;
use Modules\Projects\Models\TemplateTask;

/** Projects in the client's data export: templates, projects, tasks, their people and notes. */
class ProjectsExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'projects';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            'templates' => $this->rows(Template::class, $organization, $organizationIds, fn (Template $item) => [
                'id' => $item->getKey(), 'kept_by' => $item->organization_id, 'name' => $json($item->texts('name')), 'service' => $item->service, 'is_active' => $item->is_active,
            ]),
            'template_steps' => $this->rows(TemplateTask::class, $organization, $organizationIds, fn (TemplateTask $item) => [
                'template_id' => $item->template_id, 'sort_order' => $item->sort_order, 'title' => $json($item->texts('title')), 'due_after_days' => $item->due_after_days,
            ]),
            'projects' => $this->rows(Project::class, $organization, $organizationIds, fn (Project $item) => [
                'id' => $item->getKey(), 'unit_id' => $item->organization_id, 'code' => $item->code, 'name' => $item->name, 'client_contact_id' => $item->client_contact_id,
                'client_name' => $item->client_name, 'service' => $item->service, 'manager_id' => $item->manager_id, 'status' => $item->status, 'status_reason' => $item->status_reason,
                'starts_on' => $item->starts_on?->toDateString(), 'due_on' => $item->due_on?->toDateString(), 'finished_on' => $item->finished_on?->toDateString(), 'description' => $item->description,
            ]),
            'tasks' => $this->rows(Task::class, $organization, $organizationIds, fn (Task $item) => [
                'id' => $item->getKey(), 'project_id' => $item->project_id, 'title' => $item->title, 'description' => $item->description, 'status' => $item->status,
                'priority' => $item->priority, 'due_on' => $item->due_on?->toDateString(), 'done_at' => $item->done_at?->toIso8601String(), 'done_by' => $item->done_by,
            ]),
            'task_people' => $this->rows(TaskAssignee::class, $organization, $organizationIds, fn (TaskAssignee $item) => [
                'task_id' => $item->task_id, 'user_id' => $item->user_id, 'is_lead' => $item->is_lead, 'since' => $item->created_at?->toIso8601String(),
            ]),
            'task_notes' => $this->rows(TaskNote::class, $organization, $organizationIds, fn (TaskNote $item) => [
                'task_id' => $item->task_id, 'user_id' => $item->user_id, 'body' => $item->body, 'at' => $item->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
