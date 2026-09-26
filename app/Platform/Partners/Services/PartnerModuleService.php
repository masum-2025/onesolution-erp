<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Events\ModuleEnabled;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleCache;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Partners\Models\PartnerModule;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A partner turns a module on / off (optionally locked) for all of its
 * clients. Unlocked, it is a default each client may change; locked, it
 * decides for everyone. Module events fire for the units whose effective
 * state really changed, once at the highest such unit.
 */
class PartnerModuleService
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private ModuleCache $cache,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<string> Modules the partner level must offer, or null for every module.
     */
    public function offered(Partner $partner): ?array
    {
        $offered = $this->rules->get('partners.allowed_modules', $this->contexts->forPartner($partner));

        return is_array($offered) ? $offered : null;
    }

    /**
     * @return array{also_enabled: list<string>}
     */
    public function set(Partner $partner, string $key, ModuleState $state, bool $lock, string $reason, User $actor): array
    {
        $module = $this->registry->get($key);

        if ($module->isCore && $state === ModuleState::Disabled) {
            throw ModuleException::coreModule($module->label());
        }

        $offered = $this->offered($partner);
        if ($state === ModuleState::Enabled && $offered !== null && ! $module->isCore && ! in_array($key, $offered, true)) {
            throw PartnerException::moduleNotOffered($module->label());
        }

        return DB::transaction(function () use ($partner, $key, $state, $lock, $reason, $actor) {
            PartnerModule::query()->where('partner_id', $partner->getKey())->lockForUpdate()->get();

            $tree = Organization::query()->where('partner_id', $partner->getKey())->orderBy('depth')->get();
            $before = $this->snapshot($tree);

            $old = PartnerModule::query()->where('partner_id', $partner->getKey())->where('module_key', $key)->first();
            $also = [];

            if ($state === ModuleState::Inherit) {
                $old?->delete();
            } else {
                $this->write($partner, $key, $state, $lock && $state !== ModuleState::Inherit, $actor);

                // Turning a module on for everyone also turns on what it needs (where the partner set nothing).
                if ($state === ModuleState::Enabled) {
                    foreach ($this->registry->dependenciesOf($key) as $dependency) {
                        $exists = PartnerModule::query()->where('partner_id', $partner->getKey())->where('module_key', $dependency)->exists();
                        if (! $exists) {
                            $this->write($partner, $dependency, ModuleState::Enabled, false, $actor);
                            $also[] = $dependency;
                        }
                    }
                }
            }

            $this->flush($tree);
            $after = $this->snapshot($tree);
            $this->dispatch($tree, $before, $after, $actor, $reason);

            $this->audit->record(
                action: 'partner.module_changed',
                old: ['module' => $key, 'state' => $old?->state->value ?? 'inherit', 'locked' => (bool) $old?->locked],
                new: ['module' => $key, 'state' => $state->value, 'locked' => $lock, 'also_enabled' => $also],
                reason: $reason,
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            sort($also);

            return ['also_enabled' => $also];
        });
    }

    private function write(Partner $partner, string $key, ModuleState $state, bool $locked, User $actor): void
    {
        PartnerModule::query()->updateOrCreate(
            ['partner_id' => $partner->getKey(), 'module_key' => $key],
            ['state' => $state, 'locked' => $locked, 'updated_by' => $actor->getKey()],
        );
    }

    /**
     * @param  Collection<int, Organization>  $tree
     * @return array<string, array<string, bool>> organization id => module key => enabled
     */
    private function snapshot(Collection $tree): array
    {
        return $tree->mapWithKeys(fn (Organization $node) => [
            $node->getKey() => array_map(fn ($resolved) => $resolved->enabled, $this->resolver->fresh($node)),
        ])->all();
    }

    /**
     * @param  Collection<int, Organization>  $tree
     */
    private function flush(Collection $tree): void
    {
        $tree->pluck('root_id')->unique()->each(fn (string $root) => $this->cache->flushTree($root));
    }

    /**
     * @param  Collection<int, Organization>  $tree  Top first.
     * @param  array<string, array<string, bool>>  $before
     * @param  array<string, array<string, bool>>  $after
     */
    private function dispatch(Collection $tree, array $before, array $after, User $actor, string $reason): void
    {
        foreach ($tree as $node) {
            foreach ($after[$node->getKey()] as $key => $enabled) {
                if ($before[$node->getKey()][$key] === $enabled) {
                    continue;
                }

                $parentChanged = $node->parent_id !== null
                    && ($before[$node->parent_id][$key] ?? null) !== ($after[$node->parent_id][$key] ?? null)
                    && ($after[$node->parent_id][$key] ?? null) === $enabled;

                if (! $parentChanged) {
                    $enabled
                        ? ModuleEnabled::dispatch($node, $key, $actor, $reason)
                        : ModuleDisabled::dispatch($node, $key, $actor, $reason);
                }
            }
        }
    }
}
