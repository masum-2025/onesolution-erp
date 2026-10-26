<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Http\Controllers\Concerns\FindsPlace;
use Modules\Expenses\Http\ExpensesPresenter;
use Modules\Expenses\Http\Requests\DecideRequest;
use Modules\Expenses\Http\Requests\PayRequest;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Services\Claims;
use Modules\Expenses\Services\Place;

/**
 * Claims of the people at a unit (and below it), for those who decide
 * (expenses.approve), pay (expenses.pay) or oversee them (expenses.manage):
 * the list (or only what waits for this person), one claim with its
 * receipts, a decision on its next step (never one's own) and paying it.
 */
class ClaimController extends Controller
{
    use FindsPlace;

    private const SEES = ['expenses.approve', 'expenses.pay', 'expenses.manage'];

    public function __construct(private Place $place, private Claims $claims, private ExpensesPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Claim::STATUSES)],
            'waiting' => ['nullable', 'boolean'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $list = $this->place->query(Claim::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            // Drafts are their writers' own.
            ->where('status', '!=', Claim::DRAFT)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['period'] ?? null, fn ($query, $period) => $query->where('pay_period', $period))
            ->orderByDesc('submitted_at')->limit(300)->get();
        if ($request->boolean('waiting')) {
            $list = $list->filter(fn (Claim $claim) => $this->claims->canDecide($company, $claim, $request->user()))->values();
        }

        $rows = $this->presenter->claims($company, $list);
        // A name or claim number typed in (names live in HRM, so matched here, not in the query).
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower($row['employee']['name'].' '.$row['employee']['code'].' '.$row['number'].' '.$row['title']), $q)));
        }

        return response()->json(['data' => $rows, 'meta' => [
            'payroll' => app(ModuleResolver::class)->isEnabled('payroll', $company),
            'can_pay' => Gate::allows('expenses.pay', $unit),
        ]]);
    }

    public function show(Request $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->claimIn($unit, $company, $claim);
        abort_if($found->status === Claim::DRAFT, 404);

        return response()->json(['data' => $this->presenter->detail($company, $found, $this->can($company, $found, $request))]);
    }

    /** approve | reject */
    public function decide(DecideRequest $request, string $organization, string $claim, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['approve', 'reject'], true), 404);
        $found = $this->claimIn($unit, $company, $claim);
        abort_unless(Gate::any(self::SEES, $this->unitOf($found->unit_id)), 403);
        $version = (int) $request->validated('base_version');
        $done = $step === 'approve'
            ? $this->claims->approve($company, $found, $version, $request->validated('note'), $request->user())
            : $this->claims->reject($company, $found, $version, (string) $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->detail($company, $done, $this->can($company, $done, $request))]);
    }

    public function pay(PayRequest $request, string $organization, string $claim): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->claimIn($unit, $company, $claim);
        Gate::authorize('expenses.pay', $this->unitOf($found->unit_id));
        $paid = $this->claims->pay($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->detail($company, $paid, $this->can($company, $paid, $request))]);
    }

    /**
     * @return array<string, bool>
     */
    private function can(Organization $company, Claim $claim, Request $request): array
    {
        return [
            'decide' => $this->claims->canDecide($company, $claim, $request->user()),
            'pay' => $claim->status === Claim::APPROVED && Gate::allows('expenses.pay', $this->unitOf($claim->unit_id)),
        ];
    }
}
