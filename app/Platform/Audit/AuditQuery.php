<?php

namespace App\Platform\Audit;

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
    /**
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, action?: ?string, actor?: ?string, filter?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function forOrganization(Organization $organization, array $filters = []): Builder
    {
        return AuditLog::query()
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

    /** The action in the reader's language, or the action key itself. */
    public function label(string $action): string
    {
        $key = 'audit.actions.'.str_replace('.', '_', $action);
        $text = __($key);

        return $text === $key ? $action : $text;
    }

    /** Whether an entry is about money or pay (longer retention). */
    public function isMoney(string $action): bool
    {
        return Str::is(config('audit.money_actions', []), $action);
    }
}
