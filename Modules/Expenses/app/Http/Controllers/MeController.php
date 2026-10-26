<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Http\Controllers\Concerns\FindsPlace;
use Modules\Expenses\Http\ExpensesPresenter;
use Modules\Expenses\Http\Requests\ClaimRequest;
use Modules\Expenses\Http\Requests\ReceiptRequest;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Models\Receipt;
use Modules\Expenses\Services\Categories;
use Modules\Expenses\Services\Claims;
use Modules\Expenses\Services\Place;
use Modules\Expenses\Services\Receipts;

/**
 * The signed-in employee's own claims (expenses.claim; their login linked
 * to them in HRM): drafts with lines and receipts, sending them, taking
 * them back before anyone decided, and the kinds and limits to know.
 */
class MeController extends Controller
{
    use FindsPlace;

    public function __construct(
        private Place $place,
        private Claims $claims,
        private Receipts $receipts,
        private ExpensesPresenter $presenter,
    ) {}

    public function show(Request $request, string $organization, Categories $categories, RuleResolver $rules, RuleContextFactory $contexts, ModuleResolver $modules): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $me = $this->me($company, $request->user());
        $list = $this->place->query(Claim::class, $company)->where('employee_id', $me->id)->orderByDesc('created_at')->limit(200)->get();
        $context = $contexts->forOrganization($company);

        return response()->json(['data' => [
            'employee' => ['id' => $me->id, 'name' => $me->name, 'code' => $me->code],
            'claims' => $this->presenter->claims($company, $list),
            'categories' => $categories->of($company, true)->map(fn ($category) => $this->presenter->category($category))->values(),
            'limits' => [
                'receipt_required_above' => (int) $rules->get('expenses.receipt_required_above', $context),
                'second_approval_above' => (int) $rules->get('expenses.second_approval_above', $context),
                'max_claim_age_days' => (int) $rules->get('expenses.max_claim_age_days', $context),
                'mileage_rate' => (int) $rules->get('expenses.mileage_rate', $context),
                'receipt_max_kb' => (int) $rules->get('expenses.receipt_max_kb', $context),
            ],
            'payroll' => $modules->isEnabled('payroll', $company),
        ]]);
    }

    public function claim(Request $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);

        return response()->json(['data' => $this->presenter->detail($company, $found, $this->ownCan($found))]);
    }

    public function store(ClaimRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $claim = $this->claims->draft($company, $this->me($company, $request->user()), $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->detail($company, $claim, $this->ownCan($claim))], 201);
    }

    public function update(ClaimRequest $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);
        $updated = $this->claims->update($company, $found, $request->validated());

        return response()->json(['data' => $this->presenter->detail($company, $updated, $this->ownCan($updated))]);
    }

    public function destroy(Request $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);
        $this->claims->discard($company, $found, (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version']);

        return response()->json(['data' => ['removed' => true]]);
    }

    /** submit | withdraw */
    public function step(Request $request, string $organization, string $claim, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        abort_unless(in_array($step, ['submit', 'withdraw'], true), 404);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $done = $step === 'submit'
            ? $this->claims->submit($company, $found, $version, $request->user())
            : $this->claims->withdraw($company, $found, $version, $request->user());

        return response()->json(['data' => $this->presenter->detail($company, $done, $this->ownCan($done))]);
    }

    public function addReceipt(ReceiptRequest $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);
        $this->receipts->add($company, $found, $request->file('file'), $request->validated('line_id'), $request->user());

        return response()->json(['data' => $this->presenter->detail($company, $found, $this->ownCan($found))], 201);
    }

    public function removeReceipt(Request $request, string $organization, string $claim, string $receipt): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $found = $this->ownClaim($company, $this->me($company, $request->user()), $claim);
        $file = $this->place->query(Receipt::class, $company)->where('claim_id', $found->getKey())->whereKey($receipt)->first() ?? throw ExpensesException::receiptNotFound();
        $this->receipts->remove($company, $found, $file);

        return response()->json(['data' => $this->presenter->detail($company, $found, $this->ownCan($found))]);
    }

    /**
     * @return array<string, bool>
     */
    private function ownCan(Claim $claim): array
    {
        return [
            'edit' => $claim->status === Claim::DRAFT,
            'submit' => $claim->status === Claim::DRAFT,
            'withdraw' => $claim->status === Claim::SUBMITTED && $claim->steps_done === 0,
        ];
    }
}
