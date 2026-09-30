<?php

namespace App\Platform\Audit;

use App\Platform\Modules\ModuleScheduler;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Removes audit entries past an organization's retention (Phase 9-1): the
 * only way entries ever leave the log. Applies only where advanced_audit is
 * on and a retention is set (rules advanced_audit.retention_days and
 * .money_retention_days; empty = keep for ever). Entries without an
 * organization (platform) are never removed. Every removal is itself
 * recorded, with counts.
 */
class AuditRetention
{
    private const CHUNK = 1000;

    public function __construct(
        private ModuleScheduler $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, array{general: int, money: int}> Removed entries per organization.
     */
    public function prune(): array
    {
        $removed = [];

        // The rules go down to company level: a company's value covers its branches and
        // departments; a group's covers the group's own entries.
        $organizations = $this->modules->organizationsWithModule('advanced_audit', [OrganizationType::Group, OrganizationType::Company, OrganizationType::Personal]);

        foreach ($organizations as $organization) {
            $context = $this->contexts->forOrganization($organization);
            $general = $this->rules->get('advanced_audit.retention_days', $context);
            $money = $this->rules->get('advanced_audit.money_retention_days', $context);

            $counts = [
                'general' => $general === null ? 0 : $this->remove($organization, (int) $general, money: false),
                'money' => $money === null ? 0 : $this->remove($organization, (int) $money, money: true),
            ];

            if ($counts['general'] + $counts['money'] > 0) {
                $removed[$organization->getKey()] = $counts;
                $this->audit->record(
                    action: 'audit.pruned',
                    new: [...$counts, 'retention_days' => $general, 'money_retention_days' => $money],
                    organizationId: $organization->getKey(),
                    partnerId: $organization->partner_id,
                );
            }
        }

        return $removed;
    }

    private function remove(Organization $organization, int $days, bool $money): int
    {
        // No money actions configured: nothing is "money", so the money rule removes nothing.
        if ($money && config('audit.money_actions', []) === []) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $count = 0;

        do {
            $ids = $this->entries($organization, $cutoff, $money)->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $count += DB::table('audit_logs')->whereIn('id', $ids->all())->delete();
            }
        } while ($ids->count() === self::CHUNK);

        return $count;
    }

    private function entries(Organization $organization, mixed $cutoff, bool $money): Builder
    {
        $patterns = array_map(fn (string $pattern) => str_replace('*', '%', $pattern), config('audit.money_actions', []));

        return DB::table('audit_logs')
            ->when(
                $organization->type === OrganizationType::Group,
                fn (Builder $query) => $query->where('organization_id', $organization->getKey()),
                fn (Builder $query) => $query->whereIn('organization_id', Organization::query()->subtreeOf($organization)->select('id')),
            )
            ->where('created_at', '<', $cutoff)
            // The removal record itself stays as long as the log it describes.
            ->where('action', '!=', 'audit.pruned')
            ->where(function (Builder $query) use ($patterns, $money) {
                foreach ($patterns as $pattern) {
                    $money ? $query->orWhere('action', 'like', $pattern) : $query->where('action', 'not like', $pattern);
                }
            });
    }
}
