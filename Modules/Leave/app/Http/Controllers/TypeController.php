<?php

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Leave\Http\Controllers\Concerns\FindsPlace;
use Modules\Leave\Http\LeavePresenter;
use Modules\Leave\Http\Requests\LeaveTypeRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\LeaveTypes;
use Modules\Leave\Services\Place;

/**
 * The company's kinds of leave. Anyone who may see or ask for leave reads
 * them; people with leave.manage at the company add, change or switch
 * them off, and add the usual ones from the rule leave.default_types.
 */
class TypeController extends Controller
{
    use FindsPlace;

    public function __construct(private Place $place, private LeaveTypes $types, private LeavePresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['leave.view', 'leave.request', 'leave.manage'], $unit), 403);
        $active = $request->boolean('active');

        $types = $this->place->query(LeaveType::class, $company)->when($active, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('code')->get();

        return response()->json(['data' => $types->map(fn (LeaveType $type) => $this->presenter->type($type))->values(), 'meta' => [
            'can_manage' => Gate::allows('leave.manage', $company),
        ]]);
    }

    public function store(LeaveTypeRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('leave.manage', $company);

        return response()->json(['data' => $this->presenter->type($this->types->save($company, null, $request->validated(), $request->user()))], 201);
    }

    public function update(LeaveTypeRequest $request, string $organization, string $type): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('leave.manage', $company);
        $found = $this->typeIn($company, $type);

        return response()->json(['data' => $this->presenter->type($this->types->save($company, $found, $request->validated(), $request->user()))]);
    }

    /** Add the usual kinds of leave for the company's country (those it does not have yet). */
    public function defaults(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('leave.manage', $company);
        $added = $this->types->addDefaults($company, $request->user());

        return response()->json(['data' => ['added' => $added], 'message' => trans_choice('leave::leave.types.defaults_added', $added, ['count' => $added])]);
    }
}
