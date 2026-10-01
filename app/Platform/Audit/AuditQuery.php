<?php

namespace App\Platform\Audit;

use App\Platform\Analytics\ReportingDatabase;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The public way to read the audit log (Phase 9-1): always one organization
 * and the units below it, never anyone else's entries. Used by the audit
 * screen, the advanced_audit reports and exports.
 */
class AuditQuery
{
    public function __construct(private ReportingDatabase $reporting) {}

    /**
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, action?: ?string, actor?: ?string, filter?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function forOrganization(Organization $organization, array $filters = []): Builder
    {
        return $this->scoped(AuditLog::query(), $organization, $filters);
    }

    /**
     * The same, read from the reporting replica when there is one (Phase 10-3):
     * for reports and exports, which may lag a few seconds behind.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, action?: ?string, actor?: ?string, filter?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function forReporting(Organization $organization, array $filters = []): Builder
    {
        return $this->scoped(AuditLog::on($this->reporting->connection()), $organization, $filters);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, action?: ?string, actor?: ?string, filter?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    private function scoped(Builder $query, Organization $organization, array $filters): Builder
    {
        return $query
            ->whereIn('organization_id', Organization::query()->subtreeOf($organization)->select('id'))
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonImmutable $from) => $query->where('created_at', '>=', $from->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, CarbonImmutable $to) => $query->where('created_at', '<', $to->utc()))
            // An area ("rule") or one exact action ("rule.changed").
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => str_contains($action, '.')
                ? $query->where('action', $action)
                : $query->where('action', 'like', $action.'.%'))
            ->when($filters['actor'] ?? null, fn (Builder $query, string $actor) => $query->where('actor_user_id', $actor))
            ->when(($filters['filter'] ?? null) === 'support', fn (Builder $query) => $query->where('action', 'like', 'support.%'))
            ->when(($filters['filter'] ?? null) === 'changes', fn (Builder $query) => $query->where('action', '!=', 'support.accessed'));
    }

    /**
     * The action in the reader's language, or the action key itself. A
     * module names its own actions: "hrm.employee_hired" is read from
     * "hrm::audit.employee_hired" when the platform has no label for it.
     */
    public function label(string $action): string
    {
        $key = 'audit.actions.'.str_replace('.', '_', $action);
        $text = __($key);
        if ($text !== $key) {
            return $text;
        }

        if (str_contains($action, '.')) {
            $moduleKey = Str::before($action, '.').'::audit.'.str_replace('.', '_', Str::after($action, '.'));
            $moduleText = __($moduleKey);
            if ($moduleText !== $moduleKey) {
                return $moduleText;
            }
        }

        return $action;
    }

    /** Whether an entry is about money or pay (longer retention). */
    public function isMoney(string $action): bool
    {
        return Str::is(config('audit.money_actions', []), $action);
    }
}
