<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Http\Controllers\Concerns\FindsPlace;
use Modules\Expenses\Http\ExpensesPresenter;
use Modules\Expenses\Http\Requests\AdvanceRequest;
use Modules\Expenses\Http\Requests\DecideRequest;
use Modules\Expenses\Http\Requests\SettleRequest;
use Modules\Expenses\Models\Advance;
use Modules\Expenses\Services\Advances;
use Modules\Expenses\Services\Place;

/**
 * Advances. One's own (expenses.claim; the login HR linked to the
 * employee): asking, reading, taking one back before anyone decided.
 * Those of the people at a unit (and below), for those who decide
 * (expenses.approve), pay (expenses.pay) or oversee them (expenses.manage):
 * the list (or only those overdue), one with its movements, deciding a
 * step (never one's own), paying it out and settling what is left.
 */
class AdvanceController extends Controller
{
    use FindsPlace;

    private const SEES = ['expenses.approve', 'expenses.pay', 'expenses.manage'];

    public function __construct(private Place $place, private Advances $advances, private ExpensesPresenter $presenter) {}

    public function ask(AdvanceRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $advance = $this->advances->request($company, $this->me($company, $request->user()), $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->advance($company, $advance, $this->ownCan($advance))], 201);
    }

    public function mine(Request $request, string $organization, string $advance): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);

        return response()->json(['data' => $this->presenter->advance($company, $found = $this->own($company, $request, $advance), $this->ownCan($found))]);
    }

    public function withdraw(Request $request, string $organization, string $advance): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('expenses.claim', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $done = $this->advances->withdraw($company, $this->own($company, $request, $advance), $version, $request->user());

        return response()->json(['data' => $this->presenter->advance($company, $done, $this->ownCan($done))]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', Advance::STATUSES).',overdue,waiting']]);
        $status = $filters['status'] ?? null;
        $list = $status === 'overdue'
            ? $this->advances->overdue($company, $this->place->subtreeIds($unit))
            : $this->place->query(Advance::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
                ->when($status === 'waiting', fn ($query) => $query->where('status', Advance::SUBMITTED))
                ->when($status !== null && $status !== 'waiting', fn ($query) => $query->where('status', $status))
                ->orderByDesc('created_at')->limit(300)->get();
        if ($status === 'waiting') {
            $list = $list->filter(fn (Advance $advance) => $this->advances->canDecide($company, $advance, $request->user()))->values();
        }

        return response()->json(['data' => $this->presenter->advances($company, $list)]);
    }

    public function show(Request $request, string $organization, string $advance): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->advanceIn($unit, $company, $advance);

        return response()->json(['data' => $this->presenter->advance($company, $found, $this->can($company, $found, $request))]);
    }

    /** approve | reject */
    public function decide(DecideRequest $request, string $organization, string $advance, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['approve', 'reject'], true), 404);
        $found = $this->advanceIn($unit, $company, $advance);
        abort_unless(Gate::any(self::SEES, $this->unitOf($found->unit_id)), 403);
        $done = $this->advances->decide($company, $found, $step, (int) $request->validated('base_version'), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->advance($company, $done, $this->can($company, $done, $request))]);
    }

    public function pay(SettleRequest $request, string $organization, string $advance): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->advanceIn($unit, $company, $advance);
        Gate::authorize('expenses.pay', $this->unitOf($found->unit_id));
        $paid = $this->advances->pay($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->advance($company, $paid, $this->can($company, $paid, $request))]);
    }

    public function settle(SettleRequest $request, string $organization, string $advance): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->advanceIn($unit, $company, $advance);
        Gate::authorize('expenses.pay', $this->unitOf($found->unit_id));
        $done = $this->advances->settleRest($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->advance($company, $done, $this->can($company, $done, $request))]);
    }

    private function own(Organization $company, Request $request, string $id): Advance
    {
        $me = $this->me($company, $request->user());

        return $this->place->query(Advance::class, $company)->where('employee_id', $me->id)->whereKey($id)->first() ?? throw ExpensesException::advanceNotFound();
    }

    /** An advance of someone working at the unit or below it. */
    private function advanceIn(Organization $unit, Organization $company, string $id): Advance
    {
        $advance = $this->place->query(Advance::class, $company)->whereKey($id)->first();
        if ($advance === null || ! in_array($advance->unit_id, $this->place->subtreeIds($unit), true)) {
            throw ExpensesException::advanceNotFound();
        }

        return $advance;
    }

    /**
     * @return array<string, bool>
     */
    private function can(Organization $company, Advance $advance, Request $request): array
    {
        $pays = Gate::allows('expenses.pay', $this->unitOf($advance->unit_id));

        return [
            'decide' => $this->advances->canDecide($company, $advance, $request->user()),
            'pay' => $advance->status === Advance::APPROVED && $pays,
            'settle' => $advance->status === Advance::PAID && $pays && $this->advances->outstanding($company, $advance) > 0,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function ownCan(Advance $advance): array
    {
        return ['withdraw' => $advance->status === Advance::SUBMITTED && $advance->steps_done === 0];
    }
}
