<?php

namespace App\Platform\Rules\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\Rules\RuleCache;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleDefinition;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTarget;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\RuleValueValidator;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every write to rule values goes through here: validation against the
 * schema and every ancestor lock / constraint, maker-checker approval,
 * effective dating, history, audit and cache invalidation.
 */
class RuleService
{
    public function __construct(
        private RuleCatalog $catalog,
        private RuleResolver $resolver,
        private RuleValueValidator $validator,
        private RuleCache $cache,
        private RuleTargets $targets,
        private RuleContextFactory $contexts,
        private ModuleResolver $modules,
        private AuditLogger $audit,
    ) {}

    /**
     * Store a value (set / lock) or bounds (constrain) at a target.
     *
     * $trusted skips maker-checker; only platform tooling (CLI, seeders) may pass it.
     */
    public function set(
        RuleTarget $target,
        string $key,
        RuleMode $mode,
        mixed $value,
        string $reason,
        ?User $actor = null,
        ?string $countryCode = null,
        ?CarbonInterface $effectiveFrom = null,
        bool $trusted = false,
        array $auditContext = [],
    ): RuleValue {
        $rule = $this->catalog->get($key);
        $this->assertAllowed($target, $rule, $mode, $value, $countryCode, $effectiveFrom);

        return DB::transaction(function () use ($target, $rule, $mode, $value, $reason, $actor, $countryCode, $effectiveFrom, $trusted, $auditContext) {
            $version = (int) RuleValue::query()
                ->where('rule_key', $rule->key)
                ->where('scope_type', $target->scope)
                ->where('scope_id', $target->scopeId)
                ->lockForUpdate()
                ->max('version') + 1;

            $pending = $rule->needsApproval() && ! $trusted;

            $row = RuleValue::create([
                'rule_key' => $rule->key,
                'scope_type' => $target->scope,
                'scope_id' => $target->scopeId,
                'country_code' => $countryCode,
                'mode' => $mode,
                'value' => $value,
                'effective_from' => $effectiveFrom,
                'version' => $version,
                'status' => $pending ? RuleValueStatus::PendingApproval : RuleValueStatus::Active,
                'created_by' => $actor?->getKey(),
                'reason' => $reason,
            ]);

            $this->history($row, 'created', null, $row->status, $actor, $reason);

            $this->audit->record(
                action: $pending ? 'rule.change_requested' : 'rule.changed',
                target: $row,
                new: [
                    'rule' => $rule->key,
                    'scope' => $target->scope->value,
                    'mode' => $mode->value,
                    'value' => $value,
                    'country_code' => $countryCode,
                    'effective_from' => $effectiveFrom?->toIso8601String(),
                    ...$auditContext,
                ],
                reason: $reason,
                actor: $actor,
                organizationId: $target->organization?->getKey(),
                partnerId: $target->partnerId(),
            );

            if (! $pending) {
                $this->activate($row, $target, $actor);
            }

            return $row;
        });
    }

    /**
     * Remove this level's own value (or bounds) so the inherited value applies again.
     * History stays: past dates keep resolving to the old value.
     */
    public function reset(RuleTarget $target, string $key, string $reason, ?User $actor = null, ?RuleMode $slot = null, ?string $countryCode = null): int
    {
        $rule = $this->catalog->get($key);

        if (! $rule->allowsLevel($target->scope)) {
            throw RuleException::levelNotAllowed($rule->label(), $target->scope->value);
        }

        return DB::transaction(function () use ($target, $rule, $reason, $actor, $slot, $countryCode) {
            $now = now();
            // Only values in effect now or scheduled for later; closed periods stay as history.
            $rows = $this->slotRows($rule->key, $target, $countryCode, $slot?->slot() ?? RuleMode::cases())
                ->get()
                ->filter(fn (RuleValue $row) => $row->effective_to === null || $row->effective_to->gt($now));

            if ($rows->isEmpty()) {
                throw RuleException::nothingToReset();
            }

            foreach ($rows as $row) {
                if ($row->effective_from !== null && $row->effective_from->gt($now)) {
                    $this->transition($row, RuleValueStatus::Superseded, 'superseded', $actor, $reason);
                } elseif ($row->isEffectiveAt($now)) {
                    $row->effective_to = $now;
                    $row->save();
                    $this->history($row, 'closed', $row->status, $row->status, $actor, $reason);
                }
            }

            $this->audit->record(
                action: 'rule.reset',
                new: ['rule' => $rule->key, 'scope' => $target->scope->value, 'slot' => $slot?->value ?? 'all'],
                reason: $reason,
                actor: $actor,
                organizationId: $target->organization?->getKey(),
                partnerId: $target->partnerId(),
            );

            $this->flush($target);

            return $rows->count();
        });
    }

    /**
     * Maker-checker: a different person approves a pending change. The change
     * is re-validated, since parents may have changed since it was requested.
     */
    public function approve(RuleValue $row, User $approver, ?string $reason = null): RuleValue
    {
        $this->assertReviewable($row, $approver);

        $target = $this->targets->fromStored($row->scope_type, $row->scope_id);
        $this->assertAllowed($target, $this->catalog->get($row->rule_key), $row->mode, $row->value, $row->country_code, $row->effective_from);

        return DB::transaction(function () use ($row, $approver, $reason, $target) {
            $row->forceFill(['approved_by' => $approver->getKey(), 'reviewed_at' => now(), 'review_reason' => $reason]);
            $this->transition($row, RuleValueStatus::Active, 'approved', $approver, $reason);

            $this->audit->record(
                action: 'rule.approved',
                target: $row,
                new: ['rule' => $row->rule_key, 'value' => $row->value, 'requested_by' => $row->created_by],
                reason: $reason,
                actor: $approver,
                organizationId: $target->organization?->getKey(),
                partnerId: $target->partnerId(),
            );

            $this->activate($row, $target, $approver);

            return $row;
        });
    }

    public function reject(RuleValue $row, User $reviewer, string $reason): RuleValue
    {
        if ($row->status !== RuleValueStatus::PendingApproval) {
            throw RuleException::notPending();
        }

        $target = $this->targets->fromStored($row->scope_type, $row->scope_id);

        return DB::transaction(function () use ($row, $reviewer, $reason, $target) {
            $row->forceFill(['approved_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_reason' => $reason]);
            $this->transition($row, RuleValueStatus::Rejected, 'rejected', $reviewer, $reason);

            $this->audit->record(
                action: 'rule.rejected',
                target: $row,
                new: ['rule' => $row->rule_key, 'requested_by' => $row->created_by],
                reason: $reason,
                actor: $reviewer,
                organizationId: $target->organization?->getKey(),
                partnerId: $target->partnerId(),
            );

            return $row;
        });
    }

    /**
     * Restore an earlier version as a new change (audited, approval if required).
     */
    public function rollback(RuleTarget $target, string $key, int $version, string $reason, ?User $actor = null): RuleValue
    {
        $source = RuleValue::query()
            ->where('rule_key', $key)
            ->where('scope_type', $target->scope)
            ->where('scope_id', $target->scopeId)
            ->where('version', $version)
            ->whereIn('status', [RuleValueStatus::Active, RuleValueStatus::Superseded])
            ->first() ?? throw RuleException::valueNotFound();

        return $this->set(
            $target, $key, $source->mode, $source->value, $reason, $actor, $source->country_code,
            auditContext: ['rolled_back_to_version' => $version],
        );
    }

    /**
     * Which organizations below the target would see a different value.
     *
     * @return list<array{organization_id: string, name: string, from: mixed, to: mixed}>
     */
    public function preview(RuleTarget $target, string $key, RuleMode $mode, mixed $value, ?string $countryCode = null): array
    {
        $rule = $this->catalog->get($key);
        $this->assertValueShape($rule, $mode, $value);

        $organizations = Organization::query()
            ->when($target->organization, fn (Builder $q, Organization $org) => $q->subtreeOf($org))
            ->when($target->partner, fn (Builder $q, $partner) => $q->where('partner_id', $partner->getKey()))
            ->orderBy('depth')
            ->limit((int) config('platform_rules.preview_limit'))
            ->get();

        $override = (new RuleValue)->forceFill([
            'rule_key' => $key,
            'scope_type' => $target->scope,
            'scope_id' => $target->scopeId,
            'country_code' => $countryCode,
            'mode' => $mode,
            'value' => $value,
            'status' => RuleValueStatus::Active,
        ]);

        $changes = [];

        foreach ($organizations as $organization) {
            $context = $this->contexts->forOrganization($organization);
            $before = $this->resolver->fresh($context)[$key]->value;
            $after = $this->resolver->fresh($context, null, [$override])[$key]->value;

            if ($before !== $after) {
                $changes[] = [
                    'organization_id' => $organization->getKey(),
                    'name' => $organization->displayName(),
                    'from' => $before,
                    'to' => $after,
                ];
            }
        }

        return $changes;
    }

    public function assertAllowed(RuleTarget $target, RuleDefinition $rule, RuleMode $mode, mixed $value, ?string $countryCode, ?CarbonInterface $effectiveFrom): void
    {
        $label = $rule->label();

        if (! $rule->allowsLevel($target->scope)) {
            throw RuleException::levelNotAllowed($label, $target->scope->value);
        }

        if ($countryCode !== null && ! $rule->countrySpecific) {
            throw RuleException::notCountrySpecific($label);
        }

        if ($target->organization !== null
            && $rule->moduleKey !== RuleCatalog::CORE_MODULE
            && ! $this->modules->isEnabled($rule->moduleKey, $target->organization)) {
            throw RuleException::moduleDisabled($label);
        }

        $this->assertValueShape($rule, $mode, $value);

        // A country-specific value is checked against that country's chain.
        $context = $countryCode === null ? $target->context : new RuleContext(
            $target->context->levels,
            $countryCode,
            $target->context->partnerId,
            $target->context->rootOrganizationId,
        );

        $resolved = $this->resolver->fresh($context, $effectiveFrom)[$rule->key];

        if ($resolved->isLockedByAncestor()) {
            throw RuleException::lockedByParent($label, (string) $resolved->lockedByName);
        }

        if ($resolved->constraints === []) {
            return;
        }

        $constrainedBy = (string) collect(array_slice($resolved->trace, 0, -1))
            ->last(fn (array $entry) => ($entry['constrain'] ?? null) !== null)['name'];

        if ($mode === RuleMode::Constrain) {
            if (! $this->validator->within($rule, $value, $resolved->constraints)) {
                throw RuleException::boundsWiderThanParent($label, $constrainedBy);
            }
        } elseif (! $this->validator->satisfies($rule, $value, $resolved->constraints)) {
            throw RuleException::violatesConstraint($label, $constrainedBy, $resolved->constraints);
        }
    }

    private function assertValueShape(RuleDefinition $rule, RuleMode $mode, mixed $value): void
    {
        if ($mode === RuleMode::Constrain) {
            if (($error = $this->validator->validateBounds($rule, $value)) !== null) {
                throw RuleException::invalidBounds($rule->label(), $error);
            }
        } elseif (($error = $this->validator->validate($rule, $value)) !== null) {
            throw RuleException::invalidValue($rule->label(), $error);
        }
    }

    private function assertReviewable(RuleValue $row, User $reviewer): void
    {
        if ($row->status !== RuleValueStatus::PendingApproval) {
            throw RuleException::notPending();
        }

        if ($row->created_by === $reviewer->getKey()) {
            throw RuleException::selfApproval();
        }
    }

    /**
     * Make an active row take effect: rows of the same slot are closed at its
     * start (or superseded when they start at the same moment); a later,
     * future-dated row still takes over when its time comes.
     */
    private function activate(RuleValue $row, RuleTarget $target, ?User $actor): void
    {
        $row->effective_from ??= now();
        $start = $row->effective_from;

        $others = $this->slotRows($row->rule_key, $target, $row->country_code, $row->mode->slot())
            ->whereKeyNot($row->getKey())
            ->get();

        foreach ($others as $other) {
            if ($other->effective_from !== null && $other->effective_from->eq($start)) {
                $this->transition($other, RuleValueStatus::Superseded, 'superseded', $actor, $row->reason);
            } elseif ($other->effective_from !== null && $other->effective_from->gt($start)) {
                if ($row->effective_to === null || $other->effective_from->lt($row->effective_to)) {
                    $row->effective_to = $other->effective_from;
                }
            } elseif ($other->effective_to === null || $other->effective_to->gt($start)) {
                $other->effective_to = $start;
                $other->save();
                $this->history($other, 'closed', $other->status, $other->status, $actor, $row->reason);
            }
        }

        $row->save();
        $this->history($row, 'activated', $row->status, $row->status, $actor, $row->reason);
        $this->flush($target);
    }

    /**
     * @param  list<RuleMode>  $modes
     * @return Builder<RuleValue>
     */
    private function slotRows(string $key, RuleTarget $target, ?string $countryCode, array $modes): Builder
    {
        return RuleValue::query()
            ->where('rule_key', $key)
            ->where('scope_type', $target->scope)
            ->where('scope_id', $target->scopeId)
            ->where('country_code', $countryCode)
            ->whereIn('mode', $modes)
            ->where('status', RuleValueStatus::Active)
            ->lockForUpdate();
    }

    private function transition(RuleValue $row, RuleValueStatus $status, string $action, ?User $actor, ?string $reason): void
    {
        $old = $row->status;
        $row->status = $status;
        $row->save();

        $this->history($row, $action, $old, $status, $actor, $reason);
    }

    private function history(RuleValue $row, string $action, ?RuleValueStatus $old, ?RuleValueStatus $new, ?User $actor, ?string $reason): void
    {
        RuleValueHistory::create([
            'rule_value_id' => $row->getKey(),
            'rule_key' => $row->rule_key,
            'scope_type' => $row->scope_type->value,
            'scope_id' => $row->scope_id,
            'action' => $action,
            'old_status' => $old?->value,
            'new_status' => $new?->value,
            'snapshot' => [
                'mode' => $row->mode->value,
                'value' => $row->value,
                'country_code' => $row->country_code,
                'version' => $row->version,
                'effective_from' => $row->effective_from?->toIso8601String(),
                'effective_to' => $row->effective_to?->toIso8601String(),
            ],
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason,
        ]);
    }

    private function flush(RuleTarget $target): void
    {
        $this->cache->flush($target->scope, $target->scopeId, $target->organization?->root_id);
    }
}
