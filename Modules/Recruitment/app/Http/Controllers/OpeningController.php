<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Recruitment\Http\Controllers\Concerns\FindsPlace;
use Modules\Recruitment\Http\RecruitmentPresenter;
use Modules\Recruitment\Http\Requests\OpeningRequest;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Careers;
use Modules\Recruitment\Services\Openings;
use Modules\Recruitment\Services\Place;
use Modules\Recruitment\Services\Stages;

/**
 * Job openings at a unit (and below it): seen with recruitment.view,
 * written, opened and closed with recruitment.manage where the job is.
 * "options" gives the forms what they choose from (units, HRM positions,
 * kinds of employment, the stages).
 */
class OpeningController extends Controller
{
    use FindsPlace;

    private const SEES = ['recruitment.view', 'recruitment.manage'];

    public function __construct(private Place $place, private Openings $openings, private RecruitmentPresenter $presenter) {}

    public function index(Request $request, string $organization, Careers $careers): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', Opening::STATUSES)]])['status'] ?? null;
        $list = $this->place->query(Opening::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('created_at')->limit(300)->get();

        return response()->json(['data' => $this->presenter->openings($company, $list), 'meta' => [
            'can_manage' => Gate::allows('recruitment.manage', $unit),
            'careers_slug' => $careers->slug($company),
        ]]);
    }

    public function show(string $organization, string $opening, Stages $stages): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->openingIn($unit, $company, $opening);

        return response()->json(['data' => [
            ...$this->presenter->openings($company, [$found])[0],
            'stages' => $stages->of($company),
            'can' => ['manage' => Gate::allows('recruitment.manage', $this->unitOf($found->unit_id)), 'hire' => Gate::allows('recruitment.hire', $this->unitOf($found->unit_id))],
        ]]);
    }

    public function store(OpeningRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $target = $request->validated('unit_id') ?? $unit->getKey();
        abort_unless(in_array($target, $this->place->subtreeIds($unit), true), 404);
        Gate::authorize('recruitment.manage', $this->unitOf($target));
        $opening = $this->openings->save($company, null, [...$request->validated(), 'unit_id' => $target], $request->user());

        return response()->json(['data' => $this->presenter->openings($company, [$opening])[0]], 201);
    }

    public function update(OpeningRequest $request, string $organization, string $opening): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->openingIn($unit, $company, $opening);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        if ($request->validated('unit_id') !== null) {
            abort_unless(in_array($request->validated('unit_id'), $this->place->subtreeIds($unit), true), 404);
            Gate::authorize('recruitment.manage', $this->unitOf($request->validated('unit_id')));
        }
        $saved = $this->openings->save($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->openings($company, [$saved])[0]]);
    }

    /** open | close */
    public function step(Request $request, string $organization, string $opening, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['open', 'close'], true), 404);
        $found = $this->openingIn($unit, $company, $opening);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $done = $this->openings->step($company, $found, $step, $version, $request->user());

        return response()->json(['data' => $this->presenter->openings($company, [$done])[0]]);
    }

    /** What the forms choose from: units here, HRM positions, kinds of employment (HRM's rule), the stages. */
    public function options(string $organization, EmployeeDirectory $directory, Stages $stages, RuleResolver $rules, RuleContextFactory $contexts): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any([...self::SEES, 'recruitment.interview'], $unit), 403);
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'units' => Organization::query()->subtreeOf($unit)->orderBy('depth')->get()
                ->map(fn (Organization $item) => ['id' => $item->getKey(), 'name' => $item->displayName($locale), 'depth' => $item->depth])->values(),
            'positions' => array_values($directory->positions($company)),
            'employment_types' => array_values((array) $rules->get('hrm.employment_types', $contexts->forOrganization($unit))),
            'stages' => $stages->of($company),
        ]]);
    }
}
