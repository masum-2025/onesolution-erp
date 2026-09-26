<?php

namespace App\Platform\Rules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\ResolvedModule;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Http\Requests\PreviewRuleRequest;
use App\Platform\Rules\Http\Requests\ResetRuleRequest;
use App\Platform\Rules\Http\Requests\RollbackRuleRequest;
use App\Platform\Rules\Http\Requests\SetRuleRequest;
use App\Platform\Rules\Http\RulePresenter;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleDefinition;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTarget;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Rule editor for one organization level.
 */
class OrganizationRuleController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private RuleCatalog $catalog,
        private RuleResolver $resolver,
        private RuleTargets $targets,
        private RuleService $rules,
        private RulePresenter $presenter,
        private ModuleResolver $modules,
        private AccessResolver $access,
    ) {}

    /**
     * Rules grouped by module and category, with effective values and sources.
     */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$organization, $target] = $this->load($organization, 'view');
        $moduleFilter = $request->query('module');

        $resolved = $this->resolver->all($target->context);
        $ownRows = $this->ownRows($target)->groupBy('rule_key');
        $modules = $this->modules->resolveAll($organization);

        $grouped = [];

        foreach ($this->catalog->all() as $key => $rule) {
            if (is_string($moduleFilter) && $moduleFilter !== '' && $rule->moduleKey !== $moduleFilter) {
                continue;
            }

            $grouped[$rule->moduleKey]['module'] = $rule->moduleKey;
            $grouped[$rule->moduleKey]['name'] = $this->presenter->moduleName($rule);
            $grouped[$rule->moduleKey]['categories'][$rule->category][] = $this->presenter->present(
                $rule,
                $resolved[$key],
                $target,
                $ownRows->get($key, new Collection),
                $this->moduleEnabled($rule, $modules),
                $this->mayEdit($rule, $organization),
            );
        }

        return response()->json(['data' => array_values(array_map(fn (array $group) => [
            'module' => $group['module'],
            'name' => $group['name'],
            'categories' => array_map(
                fn (string $category, array $rules) => ['category' => $category, 'label' => $rules[0]['category_label'], 'rules' => $rules],
                array_keys($group['categories']),
                array_values($group['categories']),
            ),
        ], $grouped))]);
    }

    /**
     * One rule with the full resolution trace (explain).
     */
    public function show(string $organization, string $key): JsonResponse
    {
        [$organization, $target] = $this->load($organization, 'view');
        $rule = $this->catalog->get($key);
        $resolved = $this->resolver->explain($key, $target->context);

        return response()->json(['data' => [
            ...$this->presenter->present(
                $rule,
                $resolved,
                $target,
                $this->ownRows($target)->where('rule_key', $key)->values(),
                $this->moduleEnabled($rule, $this->modules->resolveAll($organization)),
                $this->mayEdit($rule, $organization),
            ),
            'trace' => $resolved->trace,
        ]]);
    }

    public function update(SetRuleRequest $request, string $organization, string $key): JsonResponse
    {
        [, $target] = $this->loadForEdit($organization, $key);

        $row = $this->rules->set(
            $target,
            $key,
            RuleMode::from($request->validated('mode')),
            $request->validated('value'),
            $request->validated('reason'),
            $request->user(),
            $request->validated('country_code'),
            $request->validated('effective_from') ? Carbon::parse($request->validated('effective_from')) : null,
        );

        $pending = $row->status === RuleValueStatus::PendingApproval;

        return response()->json([
            'data' => $this->presenter->row($row),
            'message' => __($pending ? 'rules.messages.pending_approval' : 'rules.messages.saved'),
        ], $pending ? 202 : 200);
    }

    /**
     * Reset to inherited.
     */
    public function destroy(ResetRuleRequest $request, string $organization, string $key): JsonResponse
    {
        [, $target] = $this->loadForEdit($organization, $key);

        $slot = match ($request->validated('slot')) {
            'value' => RuleMode::Set,
            'constraint' => RuleMode::Constrain,
            default => null,
        };

        $this->rules->reset($target, $key, $request->validated('reason'), $request->user(), $slot, $request->validated('country_code'));

        return response()->json(['message' => __('rules.messages.reset')]);
    }

    /**
     * Which organizations below would see a different value.
     */
    public function preview(PreviewRuleRequest $request, string $organization, string $key): JsonResponse
    {
        [, $target] = $this->loadForEdit($organization, $key);

        return response()->json(['data' => $this->rules->preview(
            $target,
            $key,
            RuleMode::from($request->validated('mode')),
            $request->validated('value'),
            $request->validated('country_code'),
        )]);
    }

    public function history(string $organization, string $key): JsonResponse
    {
        [, $target] = $this->load($organization, 'view');
        $this->catalog->get($key);

        $entries = RuleValueHistory::query()
            ->where('rule_key', $key)
            ->where('scope_type', $target->scope->value)
            ->where('scope_id', $target->scopeId)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'action', 'old_status', 'new_status', 'snapshot', 'actor_user_id', 'reason', 'created_at']);

        $people = $this->presenter->userNames($entries->pluck('actor_user_id'));

        return response()->json(['data' => $entries->map(fn (RuleValueHistory $entry) => [
            ...$entry->toArray(),
            'actor_name' => $people[$entry->actor_user_id] ?? null,
        ])]);
    }

    public function rollback(RollbackRuleRequest $request, string $organization, string $key): JsonResponse
    {
        [, $target] = $this->loadForEdit($organization, $key);

        $row = $this->rules->rollback($target, $key, (int) $request->validated('version'), $request->validated('reason'), $request->user());
        $pending = $row->status === RuleValueStatus::PendingApproval;

        return response()->json([
            'data' => $this->presenter->row($row),
            'message' => __($pending ? 'rules.messages.pending_approval' : 'rules.messages.saved'),
        ], $pending ? 202 : 200);
    }

    /**
     * @return array{0: Organization, 1: RuleTarget}
     */
    private function load(string $organizationId, string $ability): array
    {
        $organization = $this->findVisible($organizationId);
        Gate::authorize($ability, $organization);

        return [$organization, $this->targets->organization($organization)];
    }

    /**
     * Editing needs the rule's own permission (rules.edit.{module} by default) reaching
     * the organization. A disabled module is reported by RuleService with its own message.
     *
     * @return array{0: Organization, 1: RuleTarget}
     */
    private function loadForEdit(string $organizationId, string $key): array
    {
        [$organization, $target] = $this->load($organizationId, 'view');

        if (! $this->mayEdit($this->catalog->get($key), $organization)) {
            throw new AuthorizationException(__('tenancy.errors.forbidden'));
        }

        return [$organization, $target];
    }

    private function mayEdit(RuleDefinition $rule, Organization $organization): bool
    {
        // Some values are the partner's to set for a client (e.g. per-client limits), never the client's.
        return $rule->organizationEditable && $this->access->allows($rule->editPermission, $organization, checkModule: false);
    }

    /**
     * @return Collection<int, RuleValue>
     */
    private function ownRows(RuleTarget $target): Collection
    {
        return RuleValue::query()
            ->where('scope_type', $target->scope)
            ->where('scope_id', $target->scopeId)
            ->whereIn('status', [RuleValueStatus::Active, RuleValueStatus::PendingApproval])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @param  array<string, ResolvedModule>  $modules
     */
    private function moduleEnabled(RuleDefinition $rule, array $modules): bool
    {
        return $rule->moduleKey === RuleCatalog::CORE_MODULE || ($modules[$rule->moduleKey]->enabled ?? false);
    }
}
