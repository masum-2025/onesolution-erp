<?php

namespace Modules\Appraisals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Appraisals\Http\AppraisalsPresenter;
use Modules\Appraisals\Http\Controllers\Concerns\FindsPlace;
use Modules\Appraisals\Http\Requests\CycleRequest;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Cycles;
use Modules\Appraisals\Services\Place;

/**
 * Review cycles of a unit (and below it): seen with appraisals.view,
 * written, opened and closed with appraisals.manage where the cycle is;
 * one cycle with everyone's step (and result once the manager is done).
 * "options" gives the forms what they show (units, the rules' scale,
 * bands and goal share, whether there is a self review).
 */
class CycleController extends Controller
{
    use FindsPlace;

    private const SEES = ['appraisals.view', 'appraisals.manage'];

    public function __construct(private Place $place, private Cycles $cycles, private AppraisalsPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $status = $request->validate(['status' => ['nullable', 'in:draft,open,closed']])['status'] ?? null;
        $list = $this->place->query(Cycle::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->when($status, fn ($query, $value) => $query->where('status', $value))->orderByDesc('period_to')->limit(100)->get();

        return response()->json(['data' => $this->presenter->cycles($company, $list), 'meta' => ['can_manage' => Gate::allows('appraisals.manage', $unit)]]);
    }

    public function show(string $organization, string $cycle): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->cycleIn($unit, $company, $cycle);
        $reviews = $this->place->query(Review::class, $company)->where('cycle_id', $found->getKey())->whereIn('unit_id', $this->place->subtreeIds($unit))->get();

        return response()->json(['data' => [
            ...$this->presenter->cycles($company, [$found])[0],
            'people' => collect($this->presenter->rows($company, $reviews, results: true))->sortBy(fn ($row) => $row['employee']['name'])->values(),
            'can' => ['manage' => Gate::allows('appraisals.manage', $this->unitOf($found->unit_id))],
        ]]);
    }

    public function store(CycleRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $target = $request->validated('unit_id') ?? $unit->getKey();
        abort_unless(in_array($target, $this->place->subtreeIds($unit), true), 404);
        Gate::authorize('appraisals.manage', $this->unitOf($target));
        $cycle = $this->cycles->save($company, null, [...$request->validated(), 'unit_id' => $target], $request->user());

        return response()->json(['data' => $this->presenter->cycles($company, [$cycle])[0]], 201);
    }

    public function update(CycleRequest $request, string $organization, string $cycle): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->cycleIn($unit, $company, $cycle);
        Gate::authorize('appraisals.manage', $this->unitOf($found->unit_id));
        if ($request->validated('unit_id') !== null) {
            abort_unless(in_array($request->validated('unit_id'), $this->place->subtreeIds($unit), true), 404);
            Gate::authorize('appraisals.manage', $this->unitOf($request->validated('unit_id')));
        }
        $saved = $this->cycles->save($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->cycles($company, [$saved])[0]]);
    }

    /** open | close */
    public function step(Request $request, string $organization, string $cycle, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['open', 'close'], true), 404);
        $found = $this->cycleIn($unit, $company, $cycle);
        Gate::authorize('appraisals.manage', $this->unitOf($found->unit_id));
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $done = $step === 'open' ? $this->cycles->open($company, $found, $version, $request->user()) : $this->cycles->close($company, $found, $version, $request->user());

        return response()->json(['data' => $this->presenter->cycles($company, [$done])[0]]);
    }

    public function options(string $organization, RuleResolver $rules, RuleContextFactory $contexts): JsonResponse
    {
        [$unit] = $this->place($organization);
        abort_unless(Gate::any([...self::SEES, 'appraisals.review', 'appraisals.self'], $unit), 403);
        $context = $contexts->forOrganization($unit);
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'units' => Organization::query()->subtreeOf($unit)->orderBy('depth')->get()
                ->map(fn (Organization $item) => ['id' => $item->getKey(), 'name' => $item->displayName($locale), 'depth' => $item->depth])->values(),
            'scale' => array_values((array) $rules->get('appraisals.rating_scale', $context)),
            'bands' => array_values((array) $rules->get('appraisals.bands', $context)),
            'goal_share_percent' => (int) $rules->get('appraisals.goal_share_percent', $context),
            'goals_total_100' => (bool) $rules->get('appraisals.goals_total_100', $context),
            'self_review' => (bool) $rules->get('appraisals.self_review', $context),
        ]]);
    }
}
