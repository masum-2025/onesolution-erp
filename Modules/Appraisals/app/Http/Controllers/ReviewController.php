<?php

namespace Modules\Appraisals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Appraisals\Exceptions\AppraisalsException;
use Modules\Appraisals\Http\AppraisalsPresenter;
use Modules\Appraisals\Http\Controllers\Concerns\FindsPlace;
use Modules\Appraisals\Http\Requests\GoalsRequest;
use Modules\Appraisals\Http\Requests\RatingsRequest;
use Modules\Appraisals\Http\Requests\StepRequest;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Place;
use Modules\Appraisals\Services\Reviews;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Reviews: one's own (appraisals.self; the login HR linked to the
 * employee), those one reviews as the manager (appraisals.review), and one
 * review to read and work on, by whoever may: its employee, its reviewer,
 * people viewing or managing appraisals where it is. Anyone else gets the
 * same 404 as for a review that does not exist.
 */
class ReviewController extends Controller
{
    use FindsPlace;

    public function __construct(private Place $place, private Reviews $reviews, private AppraisalsPresenter $presenter) {}

    public function mine(Request $request, string $organization, EmployeeDirectory $directory): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('appraisals.self', $unit);
        $me = $directory->forUser($company, $request->user()) ?? throw AppraisalsException::notLinked();
        $list = $this->place->query(Review::class, $company)->where('employee_id', $me->id)->whereIn('cycle_id', $this->visibleCycles($company))
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $this->presenter->rows($company, $list, results: true)]);
    }

    public function team(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('appraisals.review', $unit);
        $list = $this->place->query(Review::class, $company)->where('manager_user_id', $request->user()->getKey())->whereIn('cycle_id', $this->visibleCycles($company))
            ->orderByDesc('created_at')->limit(300)->get();

        return response()->json(['data' => $this->presenter->rows($company, $list, results: true)]);
    }

    public function show(Request $request, string $organization, string $review): JsonResponse
    {
        [$unit, $company] = $this->place($organization);

        return response()->json(['data' => $this->presenter->review($company, $this->seen($unit, $company, $review, $request->user()), $request->user())]);
    }

    public function goals(GoalsRequest $request, string $organization, string $review): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->seen($unit, $company, $review, $request->user());
        $saved = $this->reviews->saveGoals($company, $found, $request->validated('goals'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->review($company, $saved, $request->user())]);
    }

    public function ratings(RatingsRequest $request, string $organization, string $review): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->seen($unit, $company, $review, $request->user());
        $saved = $this->reviews->rate($company, $found, $request->safe()->only(['ratings', 'summary']), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->review($company, $saved, $request->user())]);
    }

    /** submit_goals | approve_goals | submit_self | submit_manager | acknowledge | send_back */
    public function step(StepRequest $request, string $organization, string $review, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['submit_goals', 'approve_goals', 'submit_self', 'submit_manager', 'acknowledge', 'send_back'], true), 404);
        $found = $this->seen($unit, $company, $review, $request->user());
        $done = $this->reviews->step($company, $found, $step, (int) $request->validated('base_version'), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->review($company, $done, $request->user())]);
    }

    /** A review at the unit (or below) that this person may see: their own, one they review, or as someone viewing or managing appraisals there. */
    private function seen(Organization $unit, Organization $company, string $id, User $user): Review
    {
        $review = $this->reviewIn($company, $id);
        if (! in_array($review->unit_id, $this->place->subtreeIds($unit), true) || ! in_array($review->cycle_id, $this->visibleCycles($company), true)) {
            throw AppraisalsException::reviewNotFound();
        }
        $where = $this->unitOf($review->unit_id);
        $allowed = ($this->reviews->isEmployee($company, $review, $user) && Gate::allows('appraisals.self', $where))
            || $this->reviews->isReviewer($company, $review, $user)
            || Gate::any(['appraisals.view', 'appraisals.manage'], $where);
        if (! $allowed) {
            throw AppraisalsException::reviewNotFound();
        }

        return $review;
    }

    /** @return list<string> Cycles opened or closed (drafts have no reviews to work on). */
    private function visibleCycles(Organization $company): array
    {
        return $this->place->query(Cycle::class, $company)->where('status', '!=', Cycle::DRAFT)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
