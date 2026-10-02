<?php

namespace Modules\Hrm\Jobs;

use App\Models\User;
use App\Platform\Modules\Jobs\EnsureModuleEnabledForJob;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\ImportRowStatus;
use Modules\Hrm\Enums\ImportStatus;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Services\EmployeeImporter;

/**
 * Runs a started import as the person who started it: their context is
 * entered again and hrm.manage checked at the import's unit now, so someone
 * who lost access meanwhile imports nothing. Skipped while HRM is off (the
 * import stays queued; it can be cancelled). Safe to retry: rows already
 * imported are not hired twice.
 */
class ImportEmployees implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        public string $importId,
        public string $organizationId,
        public string $companyId,
        public string $actorId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureModuleEnabledForJob('hrm', $this->organizationId)];
    }

    public function backoff(): int
    {
        return 30;
    }

    public function handle(EmployeeImporter $importer, ContextResolver $resolver, CurrentContext $context): void
    {
        $company = Organization::query()->find($this->companyId);
        $actor = User::query()->find($this->actorId);
        $import = $company === null ? null : EmployeeImport::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->find($this->importId);

        if ($import === null || ! in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true)) {
            return;
        }

        // On a sync queue this runs inside the starter's own request: their context stays as it is.
        $entered = false;

        try {
            if ($actor === null) {
                throw OrganizationAccessDenied::notMember();
            }
            if (! ($context->hasOrganization() && $context->user()?->is($actor))) {
                $resolver->enterOrganization($actor, $this->organizationId, checkSignIn: false);
                $entered = true;
            }
            $allowed = Gate::forUser($actor)->allows('hrm.manage', Organization::query()->findOrFail($this->organizationId));
        } catch (OrganizationAccessDenied) {
            $allowed = false;
        }

        try {
            if (! $allowed) {
                $import->rows()->where('status', ImportRowStatus::Valid->value)->update([
                    'status' => ImportRowStatus::Failed->value,
                    'errors' => json_encode(['row' => __('hrm::hrm.import.no_access')]),
                ]);
                $importer->finish($import, ImportStatus::Done);

                return;
            }

            $importer->run($import, $actor);
        } finally {
            if ($entered) {
                $context->clear();
            }
        }
    }
}
