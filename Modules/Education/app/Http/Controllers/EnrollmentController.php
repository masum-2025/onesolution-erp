<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Section;
use Modules\Education\Models\Student;
use Modules\Education\Services\Access;
use Modules\Education\Services\Education;
use Modules\Education\Services\Enrollments;

/**
 * A section's students in roll order (education.view; a teacher only their
 * own sections), moving a student to another section and numbering rolls
 * (education.edit_students).
 */
class EnrollmentController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Enrollments $enrollments, private Access $access, private EducationPresenter $presenter) {}

    public function roster(Request $request, string $organization, string $section): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        /** @var Section $found */
        $found = $this->recordIn(Section::class, $unit, $company, $section, 'section');
        Gate::authorize('education.view', $unit);
        $sections = $this->access->sections($company, $unit, $request->user());
        if ($sections !== null && ! in_array($found->getKey(), $sections, true)) {
            throw EducationException::notFound('section');
        }

        // Rolls in order, students without one yet at the end.
        $enrollments = $this->education->query(Enrollment::class, $company)->where('section_id', $found->getKey())->where('status', 'active')->get()
            ->sortBy(fn (Enrollment $enrollment) => $enrollment->roll_no ?? PHP_INT_MAX)->values();
        $students = $this->education->query(Student::class, $company)->whereKey($enrollments->pluck('student_id'))->get()->keyBy('id');

        return response()->json(['data' => [
            'section' => $this->presenter->structure('sections', $found),
            'taken' => $enrollments->count(),
            'students' => $enrollments->map(fn (Enrollment $enrollment) => [
                ...$this->presenter->enrollment($enrollment),
                'code' => $students[$enrollment->student_id]?->code,
                'name' => $students[$enrollment->student_id]?->name,
                'name_local' => $students[$enrollment->student_id]?->name_local,
            ])->values(),
        ]]);
    }

    public function place(Request $request, string $organization, string $enrollment): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Enrollment::class, $unit, $company, $enrollment, 'enrollment');
        Gate::authorize('education.edit_students', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'section_id' => ['present', 'nullable', 'string', 'size:26']]);

        return response()->json(['data' => $this->presenter->enrollment($this->enrollments->place($company, $found, $data['section_id'], (int) $data['base_version'], $request->user()))]);
    }

    /** Rolls by the rule's order (no "rolls"), or as given: [{enrollment_id, roll_no}]. */
    public function renumber(Request $request, string $organization, string $section): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Section::class, $unit, $company, $section, 'section');
        Gate::authorize('education.edit_students', $unit);
        $data = $request->validate([
            'rolls' => ['sometimes', 'array', 'max:2000'], 'rolls.*' => ['array:enrollment_id,roll_no'],
            'rolls.*.enrollment_id' => ['required', 'string', 'size:26'], 'rolls.*.roll_no' => ['required', 'integer', 'min:1', 'max:99999'],
        ]);
        $rolls = isset($data['rolls']) ? array_column($data['rolls'], 'roll_no', 'enrollment_id') : null;
        $count = $this->enrollments->renumber($company, $found, $rolls, $request->user());

        return response()->json(['data' => ['count' => $count], 'message' => __('education::education.messages.rolls_numbered', ['count' => $count])]);
    }
}
