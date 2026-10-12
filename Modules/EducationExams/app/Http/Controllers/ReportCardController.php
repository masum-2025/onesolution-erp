<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Http\Controllers\Concerns\FindsExamUnit;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\ReportCards;
use Modules\EducationExams\Services\ResultAccess;
use Modules\EducationExams\Services\ResultNotices;

/**
 * Report cards (marksheets) and tabulation sheets to print, of an exam's or
 * a combination's results at the unit and below (.view; only students whose
 * whole result the reader may see, ResultAccess), for a section or one
 * student. Sending published results to families again (.publish).
 */
class ReportCardController extends Controller
{
    use FindsExamUnit;

    /** Report cards printed at once (a section, rarely more). */
    private const MAX_CARDS = 300;

    public function __construct(private ExamOffice $office, private ReportCards $cards, private ResultAccess $access, private ResultNotices $notices) {}

    public function exam(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);

        return $this->cardsOf($request, $unit, $company, $this->examIn($unit, $company, $exam));
    }

    public function combination(Request $request, string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);

        return $this->cardsOf($request, $unit, $company, $this->recordIn(Combination::class, $unit, $company, $combination, 'combination'));
    }

    /** Published results told to families again, once a day per student. */
    public function sendExam(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);

        return $this->send($request, $unit, $company, 'exam', $this->recordIn(Exam::class, $unit, $company, $exam, 'exam'));
    }

    public function sendCombination(Request $request, string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);

        return $this->send($request, $unit, $company, 'combination', $this->recordIn(Combination::class, $unit, $company, $combination, 'combination'));
    }

    private function cardsOf(Request $request, Organization $unit, Organization $company, Exam|Combination $source): JsonResponse
    {
        $filters = $request->validate(['section_id' => ['nullable', 'string', 'size:26'], 'student_id' => ['nullable', 'string', 'size:26']]);
        $scope = $this->access->scope($company, $unit, $request->user());
        $rows = $this->office->query(Result::class, $company)->where('source_type', $source instanceof Exam ? 'exam' : 'combination')->where('source_id', $source->getKey())
            ->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['section_id'] ?? null, fn ($query, $section) => $query->where('section_id', $section))
            ->when($filters['student_id'] ?? null, fn ($query, $student) => $query->where('student_id', $student))
            ->limit(self::MAX_CARDS + 1)->get()
            ->filter(fn (Result $row) => $this->access->seesWhole($scope, $row->section_id))->values();
        if ($rows->count() > self::MAX_CARDS) {
            throw ExamException::tooManyCards(self::MAX_CARDS);
        }

        return response()->json(['data' => $this->cards->build($company, $unit, $source, $rows)]);
    }

    private function send(Request $request, Organization $unit, Organization $company, string $type, Exam|Combination $source): JsonResponse
    {
        if ($source->status !== 'published') {
            throw ExamException::wrongStatus($source->status);
        }
        $data = $request->validate(['student_ids' => ['nullable', 'array', 'max:2000'], 'student_ids.*' => ['string', 'size:26']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->notices->sendAgain($company, $type, $source->getKey(), $data['student_ids'] ?? null, $user)]);
    }
}
