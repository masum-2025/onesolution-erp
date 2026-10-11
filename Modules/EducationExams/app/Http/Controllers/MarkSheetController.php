<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Http\Controllers\Concerns\FindsExamUnit;
use Modules\EducationExams\Http\ExamPresenter;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\PaperPart;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\MarkSheets;

/**
 * An exam's mark sheets (a paper for a section): listed with
 * education_exams.view (a teacher: theirs only, ?mine=1), entered and
 * handed in by whoever may enter that sheet, approved or returned with
 * .moderate, assigned to a teacher with .manage.
 */
class MarkSheetController extends Controller
{
    use FindsExamUnit;

    public function __construct(private ExamOffice $office, private MarkSheets $sheets, private ExamPresenter $presenter) {}

    public function index(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $filters = $request->validate(['mine' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:open,submitted,approved,returned']]);
        $sheets = $this->office->query(MarkSheet::class, $company)->where('exam_id', $found->getKey())->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->get();
        $rows = $sheets->map(fn (MarkSheet $sheet) => $this->presenter->sheet($sheet, $this->sheets->mayEnter($company, $sheet, $request->user())));
        if ($filters['mine'] ?? false) {
            $rows = $rows->where('mine', true);
        }
        $academic = app(AcademicDirectory::class);

        return response()->json([
            'data' => $rows->values(),
            'sections' => collect($academic->sessionSections($company, $found->session_id, $this->unitIds($unit)))->keyBy('id')->map(fn (array $section) => array_intersect_key($section, array_flip(['id', 'level_id', 'name', 'class_teacher_id', 'students']))),
            'teachers' => $academic->teacherNames($company, $sheets->pluck('teacher_id')->filter()->unique()->values()->all()),
        ]);
    }

    /** One sheet with its students (by name) and each one's marks per part. */
    public function show(Request $request, string $organization, string $sheet): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);

        return response()->json(['data' => $this->sheetView($company, $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet'), $request)]);
    }

    public function save(Request $request, string $organization, string $sheet): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet');
        $data = $request->validate([
            'marks' => ['required', 'array', 'min:1', 'max:3000'],
            'marks.*' => ['array:student_id,part_id,marks_centi,absent'],
            'marks.*.student_id' => ['required', 'string', 'size:26'],
            'marks.*.part_id' => ['required', 'string', 'size:26'],
            'marks.*.marks_centi' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'marks.*.absent' => ['sometimes', 'boolean'],
        ]);
        $saved = $this->sheets->save($company, $found, $data['marks'], $request->user());

        return response()->json(['data' => $this->sheetView($company, $saved, $request)]);
    }

    public function submit(Request $request, string $organization, string $sheet): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet');
        $saved = $this->sheets->submit($company, $found, $this->version($request), $request->user());

        return response()->json(['data' => $this->sheetView($company, $saved, $request)]);
    }

    public function approve(Request $request, string $organization, string $sheet): JsonResponse
    {
        return $this->decide($request, $organization, $sheet, true);
    }

    public function sendBack(Request $request, string $organization, string $sheet): JsonResponse
    {
        return $this->decide($request, $organization, $sheet, false);
    }

    public function assign(Request $request, string $organization, string $sheet): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet');
        $data = $request->validate(['teacher_id' => ['nullable', 'string', 'size:26'], 'base_version' => ['required', 'integer']]);
        if (($data['teacher_id'] ?? null) !== null && app(AcademicDirectory::class)->teacherNames($company, [$data['teacher_id']]) === []) {
            throw ExamException::notFound('teacher');
        }
        $saved = $this->sheets->assign($company, $found, $data['teacher_id'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->presenter->sheet($saved, $this->sheets->mayEnter($company, $saved, $request->user()))]);
    }

    private function decide(Request $request, string $organization, string $sheet, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.moderate', $unit);
        $found = $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet');
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->sheets->decide($company, $found, $approve, $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->sheetView($company, $saved, $request)]);
    }

    /** @return array<string, mixed> */
    private function sheetView($company, MarkSheet $sheet, Request $request): array
    {
        $entries = $this->sheets->entries($company, $sheet);

        return [
            ...$this->presenter->sheet($sheet, $this->sheets->mayEnter($company, $sheet, $request->user())),
            'parts' => $entries['parts']->map(fn (PaperPart $part) => [...$part->only(['id', 'code', 'full_marks_centi', 'pass_marks_centi']), 'name' => $part->texts('name')])->values(),
            'students' => array_map(fn (array $student) => [
                ...array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])),
                'marks' => collect($entries['marks'][$student['id']] ?? [])->map(fn (Mark $mark) => ['marks_centi' => $mark->marks_centi, 'absent' => $mark->absent])->all(),
            ], $entries['students']),
        ];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['base_version' => ['required', 'integer']])['base_version'];
    }
}
