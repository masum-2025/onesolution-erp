<?php

namespace Modules\TeacherFeedback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\TeacherFeedback\Exceptions\FeedbackException;
use Modules\TeacherFeedback\Http\Requests\RoundRequest;
use Modules\TeacherFeedback\Models\Comment;
use Modules\TeacherFeedback\Models\Question;
use Modules\TeacherFeedback\Models\Rater;
use Modules\TeacherFeedback\Models\Round;
use Modules\TeacherFeedback\Services\FeedbackOffice;
use Modules\TeacherFeedback\Services\Results;
use Modules\TeacherFeedback\Services\Rounds;

/**
 * Feedback rounds for staff. Rounds of the unit, below it and above it (an
 * institution's round covers every campus) are read with
 * teacher_feedback.view_results (results) or .manage; making, opening,
 * closing and deciding on comments need .manage, at the unit or below.
 */
class RoundController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private FeedbackOffice $office,
        private Rounds $rounds,
        private Results $results,
        private AcademicDirectory $academic,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function setup(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeReading($unit);
        $context = $this->contexts->forOrganization($unit);
        $sessions = $this->academic->sessions($company);

        return response()->json(['data' => [
            'can' => [
                'manage' => Gate::allows('teacher_feedback.manage', $unit),
                'view_results' => Gate::allows('teacher_feedback.view_results', $unit) || Gate::allows('teacher_feedback.manage', $unit),
                'view_own' => Gate::allows('teacher_feedback.view_own', $unit),
            ],
            'rules' => [
                'who_rates' => (string) $this->rules->get('teacher_feedback.who_rates', $context),
                'min_responses' => (int) $this->rules->get('teacher_feedback.min_responses', $context),
                'allow_comments' => (bool) $this->rules->get('teacher_feedback.allow_comments', $context),
            ],
            'today' => $this->office->today($company)->toDateString(),
            'sessions' => collect($sessions)->map(fn (array $session) => ['id' => $session['id'], 'name' => $session['name'], 'status' => $session['status']])->values(),
            'default_questions' => array_map(fn (string $key) => [
                'en' => __("teacher_feedback::feedback.default_questions.{$key}", [], 'en'),
                'bn' => __("teacher_feedback::feedback.default_questions.{$key}", [], 'bn'),
            ], Rounds::DEFAULT_QUESTIONS),
        ]]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeReading($unit);
        $rounds = $this->office->query(Round::class, $company)->whereIn('unit_id', $this->roundUnits($unit))->orderByDesc('opens_on')->limit(200)->get();
        $responses = $this->office->query(Rater::class, $company)->whereIn('round_id', $rounds->pluck('id'))->get(['round_id'])->countBy('round_id');
        $waiting = $this->office->query(Comment::class, $company)->whereIn('round_id', $rounds->pluck('id'))->where('status', 'pending')->get(['round_id'])->countBy('round_id');

        return response()->json(['data' => $rounds->map(fn (Round $round) => [
            ...$this->round($company, $round),
            'responses' => (int) ($responses[$round->getKey()] ?? 0),
            'comments_waiting' => (int) ($waiting[$round->getKey()] ?? 0),
        ])->values()]);
    }

    public function show(Request $request, string $organization, string $round): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeReading($unit);
        $found = $this->find($company, $unit, $round);
        $manages = Gate::allows('teacher_feedback.manage', $unit);
        $results = $this->results->of($company, $found, $this->minResponses($unit), commentStatuses: $manages ? ['pending', 'approved', 'hidden'] : ['approved']);

        return response()->json(['data' => [
            ...$this->round($company, $found),
            ...$results,
        ], 'meta' => $this->results->names($company, $results['rows'])]);
    }

    public function store(RoundRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('teacher_feedback.manage', $unit);

        return response()->json(['data' => $this->round($company, $this->rounds->save($company, $unit, null, $request->validated(), $request->user()))], 201);
    }

    public function update(RoundRequest $request, string $organization, string $round): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->owned($company, $unit, $round);

        return response()->json(['data' => $this->round($company, $this->rounds->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function open(Request $request, string $organization, string $round): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->owned($company, $unit, $round);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->round($company, $this->rounds->open($company, Organization::query()->findOrFail($found->unit_id), $found, (int) $data['base_version'], $request->user()))]);
    }

    public function close(Request $request, string $organization, string $round): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->owned($company, $unit, $round);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->round($company, $this->rounds->close($company, $found, (int) $data['base_version'], $request->user()))]);
    }

    /** The teacher sees the comment from now on. */
    public function approve(Request $request, string $organization, string $round, string $comment): JsonResponse
    {
        return $this->decide($request, $organization, $round, $comment, 'approved');
    }

    /** Nobody but who manages feedback sees the comment. */
    public function hide(Request $request, string $organization, string $round, string $comment): JsonResponse
    {
        return $this->decide($request, $organization, $round, $comment, 'hidden');
    }

    private function decide(Request $request, string $organization, string $round, string $comment, string $status): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->owned($company, $unit, $round);
        $item = $this->office->query(Comment::class, $company)->where('round_id', $found->getKey())->whereKey($comment)->first()
            ?? throw FeedbackException::notFound('comment');
        $done = $this->rounds->decide($company, $item, $status, $request->user());

        return response()->json(['data' => ['id' => $done->getKey(), 'status' => $done->status]]);
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->office->companyOf($unit)];
    }

    private function authorizeReading(Organization $unit): void
    {
        if (! Gate::allows('teacher_feedback.view_results', $unit) && ! Gate::allows('teacher_feedback.manage', $unit)) {
            Gate::authorize('teacher_feedback.view_results', $unit);
        }
    }

    /** @return list<string> Where rounds seen from a unit are made: the unit, below and above it. */
    private function roundUnits(Organization $unit): array
    {
        return array_values(array_unique([...$this->office->subtreeIds($unit), ...array_map('strval', $unit->ancestorIds())]));
    }

    private function find(Organization $company, Organization $unit, string $id): Round
    {
        return $this->office->query(Round::class, $company)->whereKey($id)->whereIn('unit_id', $this->roundUnits($unit))->first()
            ?? throw FeedbackException::notFound('round');
    }

    /** A round to change: made at the unit or below, by someone who manages feedback there. */
    private function owned(Organization $company, Organization $unit, string $id): Round
    {
        $found = $this->office->query(Round::class, $company)->whereKey($id)->whereIn('unit_id', $this->office->subtreeIds($unit))->first()
            ?? throw FeedbackException::notFound('round');
        Gate::authorize('teacher_feedback.manage', $unit);

        return $found;
    }

    private function minResponses(Organization $unit): int
    {
        return (int) $this->rules->get('teacher_feedback.min_responses', $this->contexts->forOrganization($unit));
    }

    /** @return array<string, mixed> */
    private function round(Organization $company, Round $round): array
    {
        return [
            'id' => $round->getKey(),
            ...$round->only(['unit_id', 'session_id', 'name', 'status', 'who_rates', 'version']),
            'opens_on' => $round->opens_on->toDateString(),
            'closes_on' => $round->closes_on->toDateString(),
            'question_texts' => $this->office->query(Question::class, $company)->where('round_id', $round->getKey())->orderBy('sort_order')->get()
                ->map(fn (Question $question) => $question->texts('text'))->values()->all(),
        ];
    }
}
