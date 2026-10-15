<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Setup\SetupChecklist;

/**
 * What the institution still has to set up (education.view): people who set
 * it up (education.manage) get every step with what is missing and where to
 * do it; everyone else only whether it is ready.
 */
class SetupStepsController extends Controller
{
    use FindsEducation;

    public function __construct(private SetupChecklist $checklist) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.view', $unit);
        $list = $this->checklist->for($company, $unit, $this->unitIds($unit));

        return response()->json(['data' => Gate::allows('education.manage', $unit)
            ? [...$list, 'can_set_up' => true]
            : ['ready' => $list['ready'], 'done' => $list['done'], 'total' => $list['total'], 'can_set_up' => false]]);
    }
}
