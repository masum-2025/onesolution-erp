<?php

namespace Modules\TeacherFeedback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\TeacherFeedback\Exceptions\FeedbackException;
use Modules\TeacherFeedback\Models\Rater;
use Modules\TeacherFeedback\Models\Round;
use Modules\TeacherFeedback\Services\FeedbackOffice;
use Modules\TeacherFeedback\Services\Results;

/**
 * A teacher's own feedback: their averages per round, subject and section,
 * and the comments someone approved, never who gave them. Needs
 * teacher_feedback.view_own and the rule teacher_feedback.teachers_see_own;
 * the same response threshold applies.
 */
class MyFeedbackController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private FeedbackOffice $office,
        private Results $results,
        private AcademicDirectory $academic,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        $unit = $this->findVisible($organization);
        $company = $this->office->companyOf($unit);
        Gate::authorize('teacher_feedback.view_own', $unit);
        $context = $this->contexts->forOrganization($unit);
        if (! $this->rules->get('teacher_feedback.teachers_see_own', $context)) {
            throw FeedbackException::notFound('results');
        }
        $employee = $this->academic->employeeOf($company, $request->user());
        if ($employee === null) {
            return response()->json(['data' => [], 'meta' => ['teacher' => false]]);
        }

        $units = array_values(array_unique([...$this->office->subtreeIds($unit), ...array_map('strval', $unit->ancestorIds())]));
        $rated = $this->office->query(Rater::class, $company)->where('teacher_id', $employee)->distinct()->pluck('round_id');
        $rounds = $this->office->query(Round::class, $company)->whereKey($rated)->whereIn('unit_id', $units)->whereIn('status', ['open', 'closed'])->orderByDesc('opens_on')->get();
        $min = (int) $this->rules->get('teacher_feedback.min_responses', $context);
        $answer = $rounds->map(fn (Round $round) => [
            'id' => $round->getKey(), 'name' => $round->name, 'status' => $round->status,
            'opens_on' => $round->opens_on->toDateString(), 'closes_on' => $round->closes_on->toDateString(),
            ...$this->results->of($company, $round, $min, $employee),
        ])->values();

        return response()->json(['data' => $answer, 'meta' => ['teacher' => true, 'min_responses' => $min, ...$this->results->names($company, $answer->flatMap(fn ($round) => $round['rows'])->all())]]);
    }
}
