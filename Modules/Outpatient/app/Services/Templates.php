<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Models\Template;

/**
 * Prescription templates: a doctor's own (for the doctor signed in as them)
 * or for every doctor of the company (made and changed with outpatient.manage).
 * Changed with their version; removed for good (consultations keep copies of
 * what was put in). Audited by name only.
 */
class Templates
{
    public function __construct(private OutpatientStore $store, private Serials $serials, private AuditLogger $audit) {}

    /** @return Collection<int, Template> The company's shared templates and the person's own. */
    public function visible(Organization $company, User $user): Collection
    {
        $mine = $this->serials->doctorsOf($company, $user)->all();

        return $this->store->query(Template::class, $company)
            ->where(fn ($query) => $query->whereNull('doctor_id')->when($mine !== [], fn ($or) => $or->orWhereIn('doctor_id', $mine)))
            ->orderBy('name')->limit(200)->get();
    }

    public function find(Organization $company, User $user, string $id): Template
    {
        return $this->visible($company, $user)->firstWhere('id', $id) ?? throw OutpatientException::notFound('template');
    }

    /** @param  array{name: string, shared?: bool, body: array<string, mixed>}  $data */
    public function save(Organization $company, Organization $unit, ?Template $template, array $data, ?int $baseVersion, User $actor): Template
    {
        $shared = $template !== null ? $template->doctor_id === null : (bool) ($data['shared'] ?? false);
        if ($shared && ! Gate::forUser($actor)->allows('outpatient.manage', $unit)) {
            throw OutpatientException::because('shared_templates_managers', 403);
        }
        $doctorId = $shared ? null : $this->serials->doctorsOf($company, $actor)->first();
        if (! $shared && $doctorId === null && $template === null) {
            throw OutpatientException::because('not_linked');
        }

        return $this->store->transaction($company, function () use ($company, $template, $data, $baseVersion, $actor, $doctorId) {
            if ($template !== null) {
                $template = $this->store->query(Template::class, $company)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
                if ($template->version !== $baseVersion) {
                    throw OutpatientException::versionConflict(['version' => $template->version]);
                }
                $template->fill(['name' => $data['name'], 'body' => $data['body']]);
                $template->version++;
            } else {
                $template = new Template(['organization_id' => $company->getKey(), 'doctor_id' => $doctorId, 'name' => $data['name'], 'body' => $data['body'], 'created_by' => $actor->getKey()]);
            }
            $template->save();
            $this->audit->record('outpatient.template_saved', $template, new: ['name' => $template->name, 'shared' => $template->doctor_id === null], actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    public function delete(Organization $company, Organization $unit, Template $template, int $baseVersion, User $actor): void
    {
        if ($template->doctor_id === null && ! Gate::forUser($actor)->allows('outpatient.manage', $unit)) {
            throw OutpatientException::because('shared_templates_managers', 403);
        }
        $this->store->transaction($company, function () use ($company, $template, $baseVersion, $actor) {
            $template = $this->store->query(Template::class, $company)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            if ($template->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $template->version]);
            }
            $this->audit->record('outpatient.template_deleted', $template, old: ['name' => $template->name], actor: $actor, organizationId: $company->getKey());
            $template->delete();
        });
    }
}
