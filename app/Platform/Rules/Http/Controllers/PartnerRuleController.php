<?php

namespace App\Platform\Rules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\Http\Requests\RejectRuleRequest;
use App\Platform\Rules\Http\Requests\ResetRuleRequest;
use App\Platform\Rules\Http\Requests\ReviewRuleRequest;
use App\Platform\Rules\Http\Requests\SetRuleRequest;
use App\Platform\Rules\Http\RulePresenter;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTarget;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Partner console: rule values set (or locked) for every client of the partner.
 * Only partner owners change them; another owner approves sensitive changes.
 */
class PartnerRuleController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private RuleCatalog $catalog,
        private RuleResolver $resolver,
        private RuleTargets $targets,
        private RuleService $rules,
        private RulePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $target = $this->target();
        $resolved = $this->resolver->all($target->context);
        $own = $this->ownRows($target)->groupBy('rule_key');

        return response()->json(['data' => array_values(array_map(
            fn ($rule) => $this->presenter->present($rule, $resolved[$rule->key], $target, $own->get($rule->key, new Collection), true),
            array_filter($this->catalog->all(), fn ($rule) => $rule->allowsLevel(RuleScope::Partner)),
        ))]);
    }

    public function update(SetRuleRequest $request, string $key): JsonResponse
    {
        $this->assertOwner();

        $row = $this->rules->set(
            $this->target(),
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

    public function destroy(ResetRuleRequest $request, string $key): JsonResponse
    {
        $this->assertOwner();

        $slot = match ($request->validated('slot')) {
            'value' => RuleMode::Set,
            'constraint' => RuleMode::Constrain,
            default => null,
        };

        $this->rules->reset($this->target(), $key, $request->validated('reason'), $request->user(), $slot, $request->validated('country_code'));

        return response()->json(['message' => __('rules.messages.reset')]);
    }

    public function approve(ReviewRuleRequest $request, string $value): JsonResponse
    {
        $this->assertOwner();
        $row = $this->pendingRow($value);
        $this->rules->approve($row, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->row($row->fresh())]);
    }

    public function reject(RejectRuleRequest $request, string $value): JsonResponse
    {
        $this->assertOwner();
        $row = $this->pendingRow($value);
        $this->rules->reject($row, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->row($row->fresh())]);
    }

    private function target(): RuleTarget
    {
        return $this->targets->partner($this->context->partner());
    }

    private function assertOwner(): void
    {
        abort_unless($this->context->partnerUser()->role === PartnerUserRole::Owner, 403, __('tenancy.errors.forbidden'));
    }

    private function pendingRow(string $valueId): RuleValue
    {
        return RuleValue::query()
            ->whereKey($valueId)
            ->where('scope_type', RuleScope::Partner)
            ->where('scope_id', $this->context->partner()->getKey())
            ->first() ?? throw RuleException::valueNotFound();
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
            ->get();
    }
}
