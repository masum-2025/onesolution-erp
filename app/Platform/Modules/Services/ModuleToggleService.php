<?php

namespace App\Platform\Modules\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\Enums\ResolutionReason;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Events\ModuleEnabled;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Modules\ModuleCache;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\ResolvedModule;
use App\Platform\Tenancy\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Turns modules on / off / back to inherit at one organization level.
 * Every write is audited; events fire only for modules whose effective
 * state really changed at this organization.
 */
class ModuleToggleService
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private ModuleCache $cache,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<string> Dependencies that were enabled automatically.
     */
    public function enable(Organization $organization, string $key, string $reason, bool $lock = false, ?User $actor = null): array
    {
        $this->registry->get($key);

        return $this->withTransitions($organization, $actor, $reason, function (array $before) use ($organization, $key, $reason, $lock, $actor) {
            $missing = array_values(array_filter(
                $this->registry->dependenciesOf($key),
                fn (string $dependency) => ! $before[$dependency]->enabled,
            ));

            foreach ([...$missing, $key] as $candidate) {
                $this->assertCanEnable($organization, $before[$candidate]);
            }

            foreach ($missing as $dependency) {
                $this->write($organization, $dependency, ModuleState::Enabled, false, $reason, $actor, ['required_by' => $key]);
            }

            $this->write($organization, $key, ModuleState::Enabled, $lock, $reason, $actor);

            return $missing;
        });
    }

    /**
     * @return list<string> Dependent modules that were disabled as well.
     */
    public function disable(
        Organization $organization,
        string $key,
        string $reason,
        bool $lock = false,
        bool $confirm = false,
        ?User $actor = null,
    ): array {
        $module = $this->registry->get($key);

        if ($module->isCore) {
            throw ModuleException::coreModule($module->label());
        }

        return $this->withTransitions($organization, $actor, $reason, function (array $before) use ($organization, $key, $reason, $lock, $confirm, $actor) {
            $this->assertNotLockedByAncestor($before[$key]);

            $dependents = array_values(array_filter(
                $this->registry->dependentsOf($key),
                fn (string $dependent) => $before[$dependent]->enabled,
            ));

            if ($dependents !== [] && ! $confirm) {
                throw ModuleException::dependentsNeedConfirmation(
                    $this->registry->get($key)->label(),
                    $dependents,
                    $this->labels($dependents),
                );
            }

            foreach ($dependents as $dependent) {
                $this->assertNotLockedByAncestor($before[$dependent]);
            }

            foreach ($dependents as $dependent) {
                $this->write($organization, $dependent, ModuleState::Disabled, false, $reason, $actor, ['because_of' => $key]);
            }

            $this->write($organization, $key, ModuleState::Disabled, $lock, $reason, $actor);

            return $dependents;
        });
    }

    /**
     * Remove this level's own setting so the parent's decision applies again.
     */
    public function inherit(Organization $organization, string $key, string $reason, ?User $actor = null): void
    {
        $this->registry->get($key);

        $this->withTransitions($organization, $actor, $reason, function (array $before) use ($organization, $key, $reason, $actor) {
            $this->assertNotLockedByAncestor($before[$key]);
            $this->write($organization, $key, ModuleState::Inherit, false, $reason, $actor);

            return [];
        });
    }

    /**
     * Run a change inside a transaction, then fire events for every module
     * whose effective state changed at this organization.
     *
     * @template T
     *
     * @param  Closure(array<string, ResolvedModule>): T  $change
     * @return T
     */
    public function withTransitions(Organization $organization, ?User $actor, string $reason, Closure $change): mixed
    {
        return DB::transaction(function () use ($organization, $actor, $reason, $change) {
            OrganizationModule::query()->where('organization_id', $organization->getKey())->lockForUpdate()->get();

            $before = $this->resolver->fresh($organization);
            $result = $change($before);
            $after = $this->resolver->fresh($organization);

            $this->cache->flushTree($organization->root_id);

            foreach ($after as $key => $now) {
                if ($now->enabled && ! $before[$key]->enabled) {
                    ModuleEnabled::dispatch($organization, $key, $actor, $reason);
                } elseif (! $now->enabled && $before[$key]->enabled) {
                    ModuleDisabled::dispatch($organization, $key, $actor, $reason);
                }
            }

            return $result;
        });
    }

    private function assertCanEnable(Organization $organization, ResolvedModule $resolved): void
    {
        $label = $this->registry->get($resolved->key)->label();

        $this->assertNotLockedByAncestor($resolved);

        match ($resolved->reason) {
            ResolutionReason::NotInPlan => throw ModuleException::notInPlan($label),
            ResolutionReason::SectorNotAllowed => throw ModuleException::sectorNotAllowed($label),
            ResolutionReason::ConsentMissing => throw ModuleException::consentRequired($label),
            default => null,
        };
    }

    private function assertNotLockedByAncestor(ResolvedModule $resolved): void
    {
        if ($resolved->isLockedByAncestor()) {
            $lockedBy = Organization::query()->find($resolved->lockedByOrganizationId);

            throw ModuleException::lockedByParent(
                $this->registry->get($resolved->key)->label(),
                $lockedBy?->displayName() ?? '',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(
        Organization $organization,
        string $key,
        ModuleState $state,
        bool $locked,
        string $reason,
        ?User $actor,
        array $context = [],
    ): void {
        $row = OrganizationModule::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'module_key' => $key,
        ]);

        $old = [
            'state' => $row->exists ? $row->state->value : ModuleState::Inherit->value,
            'locked' => (bool) $row->locked,
        ];

        $row->fill(['state' => $state, 'locked' => $locked, 'reason' => $reason]);
        $row->forceFill(['changed_by' => $actor?->getKey(), 'changed_at' => now()])->save();

        $this->audit->record(
            action: 'module.'.match ($state) {
                ModuleState::Enabled => 'enabled',
                ModuleState::Disabled => 'disabled',
                ModuleState::Inherit => 'inherited',
            },
            target: $row,
            old: $old,
            new: ['module' => $key, 'state' => $state->value, 'locked' => $locked, ...$context],
            reason: $reason,
            actor: $actor,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );
    }

    /**
     * @param  list<string>  $keys
     */
    private function labels(array $keys): string
    {
        return implode(', ', array_map(fn (string $key) => $this->registry->get($key)->label(), $keys));
    }
}
