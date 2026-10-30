<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\Template;
use Modules\Projects\Models\TemplateTask;

/**
 * Templates of the usual steps (e.g. "BSCI audit": ten steps, each due some
 * days after the start). Kept by a group (for all its companies) or a
 * company; used at it and below; switched off, never removed. Saving a
 * template replaces its steps (projects made from it keep their own tasks).
 */
class Templates
{
    public function __construct(private Workplace $place, private AuditLogger $audit) {}

    /**
     * @return Collection<int, Template>
     */
    public function usableAt(Organization $unit, bool $all = false): Collection
    {
        return $this->place->query(Template::class, $unit, $this->place->templateOwners($unit))
            ->when(! $all, fn ($query) => $query->where('is_active', true))
            ->get()->sortBy(fn (Template $template) => mb_strtolower($template->textIn('name')))->values();
    }

    public function find(Organization $unit, string $id): Template
    {
        return $this->place->query(Template::class, $unit, $this->place->templateOwners($unit))->whereKey($id)->first()
            ?? throw ProjectsException::templateNotFound();
    }

    /**
     * @return Collection<int, TemplateTask>
     */
    public function steps(Template $template): Collection
    {
        return TemplateTask::inTenantOf($template->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->where('template_id', $template->getKey())->orderBy('sort_order')->get();
    }

    /**
     * @param  array{name: array<string, string>, service?: string|null, is_active?: bool, base_version?: int, steps: list<array{title: array<string, string>, due_after_days: int}>}  $data
     */
    public function save(Organization $owner, ?Template $template, array $data, User $actor): Template
    {
        if (! in_array($owner->type, [OrganizationType::Group, OrganizationType::Company], true)) {
            throw ProjectsException::notTemplateOwner();
        }
        if ($template !== null && $template->organization_id !== $owner->getKey()) {
            // Kept by a unit above: changed there.
            throw ProjectsException::notTemplateOwner();
        }

        return $this->place->transaction($owner, function () use ($owner, $template, $data, $actor) {
            $new = $template === null;
            if (! $new) {
                $template = $this->place->query(Template::class, $owner, [$owner->getKey()])->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
                if ($template->version !== (int) ($data['base_version'] ?? 0)) {
                    throw ProjectsException::versionConflict();
                }
            }
            $template ??= new Template(['organization_id' => $owner->getKey(), 'version' => 0]);
            $old = $new ? [] : $this->values($template);
            $template->fill(array_intersect_key($data, array_flip(['service', 'is_active'])));
            $template->putTexts('name', $data['name']);
            $template->version = ($template->version ?? 0) + 1;
            $template->save();

            $this->place->query(TemplateTask::class, $owner, [$owner->getKey()])->where('template_id', $template->getKey())->delete();
            foreach (array_values($data['steps']) as $index => $step) {
                $row = new TemplateTask(['organization_id' => $owner->getKey(), 'template_id' => $template->getKey(), 'sort_order' => $index + 1, 'due_after_days' => (int) $step['due_after_days']]);
                $row->putTexts('title', $step['title']);
                $row->save();
            }

            $this->audit->record($new ? 'projects.template_added' : 'projects.template_changed', $template, old: $old,
                new: [...$this->values($template), 'steps' => count($data['steps'])], actor: $actor, organizationId: $owner->getKey());

            return $template;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Template $template): array
    {
        return ['name' => $template->texts('name'), 'service' => $template->service, 'is_active' => $template->is_active];
    }
}
