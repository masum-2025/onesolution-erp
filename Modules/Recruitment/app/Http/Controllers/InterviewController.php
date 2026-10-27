<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Recruitment\Http\Controllers\Concerns\FindsPlace;
use Modules\Recruitment\Http\RecruitmentPresenter;
use Modules\Recruitment\Http\Requests\FeedbackRequest;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Interviews;
use Modules\Recruitment\Services\Place;

/**
 * Interviews: the ones this person is asked to (to come, and those still
 * waiting for their feedback), their feedback, and cancelling one
 * (recruitment.manage where the job is).
 */
class InterviewController extends Controller
{
    use FindsPlace;

    public function __construct(private Place $place, private Interviews $interviews, private RecruitmentPresenter $presenter) {}

    public function mine(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['recruitment.interview', 'recruitment.manage'], $unit), 403);
        $user = $request->user();
        $list = $this->place->query(Interview::class, $company)->whereJsonContains('interviewer_ids', $user->getKey())
            ->where('status', '!=', Interview::CANCELLED)->where('starts_at', '>=', now()->subDays(60))
            ->orderBy('starts_at')->limit(200)->get();
        $applications = $this->place->query(Application::class, $company)->whereKey($list->pluck('application_id')->unique()->all())->get()->keyBy(fn (Application $item) => $item->getKey());
        $people = $this->place->query(Candidate::class, $company)->whereKey($applications->pluck('candidate_id')->all())->get()->keyBy(fn (Candidate $item) => $item->getKey());
        $openings = $this->place->query(Opening::class, $company)->whereKey($applications->pluck('opening_id')->all())->get()->keyBy(fn (Opening $item) => $item->getKey());
        $given = $this->place->query(Feedback::class, $company)->whereIn('interview_id', $list->map(fn (Interview $item) => $item->getKey())->all())
            ->where('user_id', $user->getKey())->pluck('interview_id')->all();

        return response()->json(['data' => $list->map(function (Interview $interview) use ($company, $applications, $people, $openings, $given) {
            $application = $applications[$interview->application_id] ?? null;

            return [
                ...$this->presenter->interview($company, $interview),
                'name' => (string) ($application === null ? '' : $people[$application->candidate_id]?->name ?? ''),
                'opening_title' => $application === null || ! isset($openings[$application->opening_id]) ? '' : $openings[$application->opening_id]->textIn('title'),
                'application_active' => $application?->status === Application::ACTIVE,
                'feedback_given' => in_array($interview->getKey(), $given, true),
            ];
        })->values()]);
    }

    public function feedback(FeedbackRequest $request, string $organization, string $interview): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->interviewIn($company, $interview);
        $application = $this->applicationIn($unit, $company, $found->application_id);
        abort_unless(Gate::any(['recruitment.interview', 'recruitment.manage'], $this->unitOf($application->unit_id)), 403);
        $feedback = $this->interviews->feedback($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => ['score' => $feedback->score, 'recommendation' => $feedback->recommendation, 'note' => $feedback->note]]);
    }

    public function cancel(Request $request, string $organization, string $interview): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->interviewIn($company, $interview);
        $application = $this->applicationIn($unit, $company, $found->application_id);
        Gate::authorize('recruitment.manage', $this->unitOf($application->unit_id));
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];

        return response()->json(['data' => $this->presenter->interview($company, $this->interviews->cancel($company, $found, $version, $request->user()))]);
    }
}
