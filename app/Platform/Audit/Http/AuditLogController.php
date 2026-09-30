<?php

namespace App\Platform\Audit\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditQuery;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's own audit log (it and the units below it), including
 * every step of support access by the partner. Read-only; entries are
 * append-only and never edited. Filters by period, area and person (Phase 9-1).
 */
class AuditLogController extends Controller
{
    use FindsVisibleOrganizations;

    public function __invoke(AuditFilterRequest $request, string $organization, AuditQuery $audit): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('audit.view', $organization);

        $page = $audit->forOrganization($organization, $request->filters())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(PerPage::from($request, 50));

        $people = User::query()->whereKey(collect($page->items())->pluck('actor_user_id')->filter()->unique()->values())->pluck('name', 'id');
        $units = Organization::query()->whereKey(collect($page->items())->pluck('organization_id')->filter()->unique()->values())->get()
            ->mapWithKeys(fn (Organization $node) => [$node->getKey() => $node->displayName()]);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $entry) => [
                'id' => $entry->getKey(),
                'action' => $entry->action,
                'label' => $audit->label($entry->action),
                'support' => str_starts_with($entry->action, 'support.'),
                'actor' => $entry->actor_user_id === null ? null : ['id' => $entry->actor_user_id, 'name' => $people[$entry->actor_user_id] ?? null],
                'organization' => ['id' => $entry->organization_id, 'name' => $units[$entry->organization_id] ?? null],
                'target_type' => $entry->target_type,
                'old_values' => $entry->old_values,
                'new_values' => $entry->new_values,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
