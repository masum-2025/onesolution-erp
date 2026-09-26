<?php

namespace App\Platform\Packaging\Actions;

use App\Models\User;
use App\Platform\Access\Exceptions\AccessException;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Access\Services\RoleService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Enums\ResolutionReason;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Packaging\SectorPackageDefinition;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Gives a company the starting point of its sector (onboarding): modules
 * turned on at the company, default settings as company-level rule values,
 * roles cloned from templates. Everything is ordinary company data, so all
 * of it stays editable. Applied once per company and package; what could not
 * be applied (not in the plan, locked above, ...) is recorded with the reason.
 */
class ApplySectorPackage
{
    public function __construct(
        private SectorCatalog $sectors,
        private ModuleRegistry $registry,
        private ModuleResolver $modules,
        private ModuleToggleService $toggles,
        private RuleCatalog $ruleCatalog,
        private RuleService $rules,
        private RuleTargets $targets,
        private RoleService $roles,
        private AuditLogger $audit,
    ) {}

    /**
     * @return OrganizationPackage|null Null when the organization is no company or has no known sector.
     */
    public function handle(Organization $company, ?User $actor = null): ?OrganizationPackage
    {
        if (! $company->type->isCompanyLike() || ! $this->sectors->has($company->sector_key)) {
            return null;
        }

        $package = $this->sectors->get($company->sector_key);

        $existing = OrganizationPackage::query()
            ->where('organization_id', $company->getKey())
            ->where('package_key', $package->key)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $reason = "Sector package: {$package->key} ({$package->version()})";

        return DB::transaction(function () use ($company, $package, $actor, $reason) {
            $summary = [
                ...$this->applyModules($company, $package, $actor, $reason),
                ...$this->applyRules($company, $package, $actor, $reason),
                ...$this->applyRoles($company, $package, $actor, $reason),
            ];

            $record = OrganizationPackage::create([
                'organization_id' => $company->getKey(),
                'package_key' => $package->key,
                'package_version' => $package->version(),
                'applied_by' => $actor?->getKey(),
                'summary' => $summary,
            ]);

            $this->audit->record(
                action: 'organization.package_applied',
                target: $company,
                new: ['package' => $package->key, 'version' => $package->version(), ...$summary],
                reason: $reason,
                actor: $actor,
                organizationId: $company->getKey(),
                partnerId: $company->partner_id,
            );

            return $record;
        });
    }

    /**
     * @return array{modules_enabled: list<string>, modules_upgrade: list<string>, modules_skipped: list<array{key: string, reason: string}>}
     */
    private function applyModules(Organization $company, SectorPackageDefinition $package, ?User $actor, string $reason): array
    {
        $enabled = [];
        $upgrade = [];
        $skipped = [];

        foreach ($package->modules as $key) {
            if (! $this->registry->has($key)) {
                $skipped[] = ['key' => $key, 'reason' => 'unknown_module'];

                continue;
            }

            $resolved = $this->modules->fresh($company)[$key];

            if ($resolved->enabled) {
                continue;
            }

            if ($resolved->reason === ResolutionReason::NotInPlan) {
                $upgrade[] = $key;

                continue;
            }

            try {
                $also = $this->toggles->enable($company, $key, $reason, actor: $actor);
                array_push($enabled, ...$also, ...[$key]);
            } catch (ModuleException $exception) {
                $skipped[] = ['key' => $key, 'reason' => $exception->errorCode()];
            }
        }

        return [
            'modules_enabled' => array_values(array_unique($enabled)),
            'modules_upgrade' => $upgrade,
            'modules_skipped' => $skipped,
        ];
    }

    /**
     * @return array{rules_set: list<string>, rules_skipped: list<array{key: string, reason: string}>}
     */
    private function applyRules(Organization $company, SectorPackageDefinition $package, ?User $actor, string $reason): array
    {
        $set = [];
        $skipped = [];
        $target = $this->targets->organization($company->fresh());

        foreach ($package->rules as $entry) {
            if (! $this->ruleCatalog->has($entry['key'])) {
                $skipped[] = ['key' => $entry['key'], 'reason' => 'unknown_rule'];

                continue;
            }

            // Never overwrite what the company already chose.
            $own = RuleValue::query()
                ->where('rule_key', $entry['key'])
                ->where('scope_type', $target->scope)
                ->where('scope_id', $target->scopeId)
                ->whereIn('status', [RuleValueStatus::Active, RuleValueStatus::PendingApproval])
                ->exists();

            if ($own) {
                $skipped[] = ['key' => $entry['key'], 'reason' => 'already_set'];

                continue;
            }

            try {
                // Platform onboarding, like seeders: no maker-checker, but audited.
                $this->rules->set($target, $entry['key'], RuleMode::from($entry['mode'] ?? 'set'), $entry['value'], $reason, $actor, trusted: true);
                $set[] = $entry['key'];
            } catch (RuleException $exception) {
                $skipped[] = ['key' => $entry['key'], 'reason' => $exception->errorCode()];
            }
        }

        return ['rules_set' => $set, 'rules_skipped' => $skipped];
    }

    /**
     * @return array{roles_created: list<string>, roles_skipped: list<array{key: string, reason: string}>}
     */
    private function applyRoles(Organization $company, SectorPackageDefinition $package, ?User $actor, string $reason): array
    {
        $created = [];
        $skipped = [];

        foreach ($package->roleTemplates as $templateKey) {
            $template = RoleTemplate::query()->where('key', $templateKey)->whereNull('deprecated_at')->first();

            if ($template === null) {
                $skipped[] = ['key' => $templateKey, 'reason' => 'unknown_template'];

                continue;
            }

            if (Role::query()->where('organization_id', $company->getKey())->where('template_key', $templateKey)->exists()) {
                $skipped[] = ['key' => $templateKey, 'reason' => 'already_exists'];

                continue;
            }

            try {
                $this->roles->createFromTemplateForPackage($company, $template, $actor, $reason);
                $created[] = $templateKey;
            } catch (AccessException $exception) {
                $skipped[] = ['key' => $templateKey, 'reason' => $exception->errorCode()];
            }
        }

        return ['roles_created' => $created, 'roles_skipped' => $skipped];
    }
}
