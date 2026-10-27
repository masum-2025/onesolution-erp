<?php

namespace Modules\Appraisals\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Appraisals\Http\AppraisalsPresenter;
use Modules\Appraisals\Http\Controllers\Concerns\FindsPlace;
use Modules\Appraisals\Http\Requests\CompetencyRequest;
use Modules\Appraisals\Services\Competencies;

/** The company's competencies: seen by anyone of the module, changed by those who manage appraisals (audited). */
class CompetencyController extends Controller
{
    use FindsPlace;

    public function __construct(private Competencies $competencies, private AppraisalsPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['appraisals.view', 'appraisals.manage', 'appraisals.review', 'appraisals.self'], $unit), 403);

        return response()->json([
            'data' => $this->competencies->of($company)->map(fn ($competency) => $this->presenter->competency($competency))->values(),
            'meta' => ['can_manage' => Gate::allows('appraisals.manage', $company)],
        ]);
    }

    public function store(CompetencyRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('appraisals.manage', $company);

        return response()->json(['data' => $this->presenter->competency($this->competencies->save($company, null, $request->validated(), $request->user()))], 201);
    }

    public function update(CompetencyRequest $request, string $organization, string $competency): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('appraisals.manage', $company);
        $found = $this->competencyIn($company, $competency);

        return response()->json(['data' => $this->presenter->competency($this->competencies->save($company, $found, $request->validated(), $request->user()))]);
    }

    public function defaults(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('appraisals.manage', $company);
        $added = $this->competencies->addDefaults($company, $request->user());

        return response()->json(['data' => ['added' => $added], 'message' => trans_choice('appraisals::appraisals.competencies_added', $added, ['count' => $added])]);
    }
}
