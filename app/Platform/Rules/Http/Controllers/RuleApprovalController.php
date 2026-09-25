<?php

namespace App\Platform\Rules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\Http\Requests\RejectRuleRequest;
use App\Platform\Rules\Http\Requests\ReviewRuleRequest;
use App\Platform\Rules\Http\RulePresenter;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Maker-checker for organization-level rule changes: an owner of the same
 * organization or of an ancestor (other than the requester) reviews them.
 */
class RuleApprovalController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private RuleService $rules,
        private RulePresenter $presenter,
        private RuleCatalog $catalog,
        private CurrentContext $context,
    ) {}

    /**
     * Pending changes at this organization and below.
     */
    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('rules.manage', $organization);

        $pending = RuleValue::query()
            ->where('status', RuleValueStatus::PendingApproval)
            ->whereIn('scope_type', [RuleScope::Group, RuleScope::Company, RuleScope::Branch, RuleScope::Department])
            ->whereIn('scope_id', $this->reviewableOrganizationIds($organization))
            ->orderBy('created_at')
            ->get();

        $organizations = Organization::query()
            ->whereKey($pending->pluck('scope_id')->unique()->values())
            ->get()
            ->mapWithKeys(fn (Organization $node) => [$node->getKey() => $node->displayName()]);
        $people = $this->presenter->userNames($pending->pluck('created_by'));

        return response()->json(['data' => $pending->map(fn (RuleValue $row) => [
            ...$this->presenter->row($row),
            'rule' => $row->rule_key,
            'label' => $this->catalog->has($row->rule_key) ? $this->catalog->get($row->rule_key)->label() : $row->rule_key,
            'scope' => ['level' => $row->scope_type->value, 'id' => $row->scope_id, 'name' => $organizations[$row->scope_id] ?? null],
            'requested_by' => $row->created_by === null ? null : ['id' => $row->created_by, 'name' => $people[$row->created_by] ?? null],
        ])->values()]);
    }

    public function approve(ReviewRuleRequest $request, string $organization, string $value): JsonResponse
    {
        $row = $this->pendingRow($organization, $value);
        $this->rules->approve($row, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->row($row->fresh())]);
    }

    public function reject(RejectRuleRequest $request, string $organization, string $value): JsonResponse
    {
        $row = $this->pendingRow($organization, $value);
        $this->rules->reject($row, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->row($row->fresh())]);
    }

    private function pendingRow(string $organizationId, string $valueId): RuleValue
    {
        $organization = $this->findVisible($organizationId);
        Gate::authorize('rules.manage', $organization);

        $row = RuleValue::query()->whereKey($valueId)->first();

        // Same 404 for missing ids and for changes outside this subtree.
        if ($row === null
            || ! $row->scope_type->isOrganizationLevel()
            || ! $this->reviewableOrganizationIds($organization)->contains($row->scope_id)) {
            throw RuleException::valueNotFound();
        }

        return $row;
    }

    /**
     * @return Collection<int, string>
     */
    private function reviewableOrganizationIds(Organization $organization)
    {
        return Organization::query()
            ->visibleTo($this->context)
            ->subtreeOf($organization)
            ->pluck('id');
    }
}
