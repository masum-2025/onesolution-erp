<?php

namespace Modules\Education\Directory;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Education\Models\Batch;
use Modules\Education\Models\Curriculum;
use Modules\Education\Models\CurriculumItem;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\Prerequisite;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;
use Modules\Education\Models\Subject;
use Modules\Education\Services\Education;

/**
 * Education's public service for other modules (course registration,
 * fees, exams): students, their places, sessions, subjects, the curriculum
 * of a level and prerequisites, read only. Works without a tenant context:
 * every lookup names the institution (company) and stays inside it.
 * Records are plain arrays, so callers never touch Education's models.
 */
class AcademicDirectory
{
    public function __construct(private Education $education) {}

    /** The institution a unit (a campus or the institution itself) belongs to. */
    public function companyOf(Organization $unit): Organization
    {
        return $this->education->companyOf($unit);
    }

    /** @return array<string, mixed>|null */
    public function student(Organization $company, string $id): ?array
    {
        $student = $this->education->query(Student::class, $company)->whereKey($id)->first();

        return $student === null ? null : $this->studentRecord($student);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> By id; unknown ids are left out.
     */
    public function students(Organization $company, array $ids): array
    {
        return $ids === [] ? [] : $this->education->query(Student::class, $company)->whereKey($ids)->get()
            ->mapWithKeys(fn (Student $student) => [$student->getKey() => $this->studentRecord($student)])->all();
    }

    /** The student a login is (portal "self"), if any. */
    public function studentForUser(Organization $company, User $user): ?array
    {
        $student = $this->education->query(Student::class, $company)->where('user_id', $user->getKey())->whereIn('status', ['active', 'suspended'])->first();

        return $student === null ? null : $this->studentRecord($student);
    }

    /**
     * Where a student studies in a session (or now): unit, session, level, section.
     *
     * @return array{id: string, unit_id: string, session_id: string, level_id: string, section_id: ?string, status: string}|null
     */
    public function enrollment(Organization $company, string $studentId, ?string $sessionId = null): ?array
    {
        $enrollment = $this->education->query(Enrollment::class, $company)->where('student_id', $studentId)
            ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId), fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('started_on')->first();

        return $enrollment === null ? null : [
            'id' => $enrollment->getKey(), 'unit_id' => $enrollment->unit_id, 'session_id' => $enrollment->session_id,
            'level_id' => $enrollment->level_id, 'section_id' => $enrollment->section_id, 'status' => $enrollment->status,
        ];
    }

    /**
     * Students studying in a section now, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function sectionStudents(Organization $company, string $sectionId): array
    {
        $ids = $this->education->query(Enrollment::class, $company)->where('section_id', $sectionId)->where('status', 'active')->pluck('student_id')->all();

        return array_values(collect($this->students($company, $ids))->sortBy('name')->all());
    }

    /** @return array<string, mixed>|null */
    public function section(Organization $company, string $id): ?array
    {
        $section = $this->education->query(Section::class, $company)->whereKey($id)->first();

        return $section === null ? null : $section->only(['id', 'unit_id', 'session_id', 'level_id', 'name', 'is_active']);
    }

    /** @return array<string, mixed>|null */
    public function session(Organization $company, string $id): ?array
    {
        $session = $this->education->query(Session::class, $company)->whereKey($id)->first();

        return $session === null ? null : [
            'id' => $session->getKey(), 'kind' => $session->kind, 'status' => $session->status, 'name' => $session->texts('name'),
            'starts_on' => $session->starts_on->toDateString(), 'ends_on' => $session->ends_on->toDateString(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function level(Organization $company, string $id): ?array
    {
        $level = $this->education->query(Level::class, $company)->whereKey($id)->first();

        return $level === null ? null : ['id' => $level->getKey(), 'program_id' => $level->program_id, 'sequence' => $level->sequence, 'code' => $level->code, 'name' => $level->texts('name')];
    }

    /**
     * Subjects by id (code, names, credits in hundredths, kind, in use).
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function subjects(Organization $company, array $ids): array
    {
        return $ids === [] ? [] : $this->education->query(Subject::class, $company)->whereKey($ids)->get()
            ->mapWithKeys(fn (Subject $subject) => [$subject->getKey() => [
                'id' => $subject->getKey(), 'code' => $subject->code, 'name' => $subject->texts('name'),
                'credits_centi' => (int) ($subject->credits_centi ?? 0), 'kind' => $subject->kind, 'is_active' => $subject->is_active,
            ]])->all();
    }

    /**
     * The subjects of a level by the curriculum in force for a session: a
     * batch's own curriculum if it names one, else the program's active
     * curriculum that took effect latest by the session's year.
     *
     * @return list<array{subject_id: string, kind: string, stream_id: ?string, sort_order: int}>
     */
    public function curriculum(Organization $company, string $levelId, string $sessionId, ?string $batchId = null): array
    {
        $level = $this->education->query(Level::class, $company)->whereKey($levelId)->first();
        $session = $this->education->query(Session::class, $company)->whereKey($sessionId)->first();
        if ($level === null || $session === null) {
            return [];
        }
        $curriculumId = $batchId === null ? null : $this->education->query(Batch::class, $company)->whereKey($batchId)->value('curriculum_id');
        $curriculumId ??= $this->education->query(Curriculum::class, $company)->where('program_id', $level->program_id)->where('status', 'active')
            ->where('effective_from_year', '<=', (int) $session->starts_on->format('Y'))->orderByDesc('effective_from_year')->value('id');
        if ($curriculumId === null) {
            return [];
        }

        return $this->education->query(CurriculumItem::class, $company)->where('curriculum_id', $curriculumId)->where('level_id', $levelId)
            ->orderBy('sort_order')->get()
            ->map(fn (CurriculumItem $item) => ['subject_id' => $item->subject_id, 'kind' => $item->kind, 'stream_id' => $item->stream_id, 'sort_order' => (int) $item->sort_order])
            ->values()->all();
    }

    /**
     * What each subject needs first (subject id => subject ids).
     *
     * @param  list<string>  $subjectIds
     * @return array<string, list<string>>
     */
    public function prerequisites(Organization $company, array $subjectIds): array
    {
        return $subjectIds === [] ? [] : $this->education->query(Prerequisite::class, $company)->whereIn('subject_id', $subjectIds)->get()
            ->groupBy('subject_id')->map(fn (Collection $rows) => $rows->pluck('requires_subject_id')->all())->all();
    }

    /**
     * Students found by name (English or own script), code or phone, studying
     * at the given campuses, by name (a few at a time, for pickers).
     *
     * @param  list<string>  $unitIds
     * @return list<array<string, mixed>>
     */
    public function search(Organization $company, string $term, array $unitIds, int $limit = 20): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $digits = (string) preg_replace('/\D/', '', strtr($term, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));

        return $this->education->query(Student::class, $company)->whereIn('unit_id', $unitIds)->whereIn('status', ['active', 'suspended'])
            ->where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('name_local', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")
                ->when(strlen($digits) >= 5, fn ($or) => $or->orWhere('phone', 'like', "%{$digits}%")))
            ->orderBy('name')->limit($limit)->get()->map(fn (Student $student) => $this->studentRecord($student))->values()->all();
    }

    /**
     * Subjects in use, by code (for pickers).
     *
     * @return list<array<string, mixed>>
     */
    public function allSubjects(Organization $company): array
    {
        $ids = $this->education->query(Subject::class, $company)->where('is_active', true)->orderBy('code')->limit(2000)->pluck('id')->all();

        return collect($this->subjects($company, $ids))->sortBy('code')->values()->all();
    }

    /**
     * Sessions, newest first (for pickers).
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(Organization $company): array
    {
        return $this->education->query(Session::class, $company)->orderByDesc('starts_on')->orderBy('sequence')->limit(200)->get()
            ->map(fn (Session $session) => $this->session($company, $session->getKey()))->values()->all();
    }

    /**
     * Levels in use with their program, in program order (for pickers).
     *
     * @return list<array<string, mixed>>
     */
    public function levels(Organization $company): array
    {
        $programs = $this->education->query(Program::class, $company)->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get()->keyBy('id');

        return $this->education->query(Level::class, $company)->where('is_active', true)->whereIn('program_id', $programs->keys())->get()
            ->sortBy(fn (Level $level) => [$programs->keys()->search($level->program_id), $level->sequence])
            ->map(fn (Level $level) => [
                'id' => $level->getKey(), 'program_id' => $level->program_id, 'sequence' => $level->sequence, 'code' => $level->code, 'name' => $level->texts('name'),
                'program' => ['id' => $level->program_id, 'code' => $programs[$level->program_id]->code, 'progression' => $programs[$level->program_id]->progression, 'name' => $programs[$level->program_id]->texts('name')],
            ])->values()->all();
    }

    /**
     * Sections of a session at the given campuses, with how many study in each.
     *
     * @param  list<string>  $unitIds
     * @return list<array<string, mixed>>
     */
    public function sessionSections(Organization $company, string $sessionId, array $unitIds): array
    {
        $sections = $this->education->query(Section::class, $company)->where('session_id', $sessionId)->whereIn('unit_id', $unitIds)->where('is_active', true)
            ->orderBy('level_id')->orderBy('name')->get();
        $counts = $this->education->query(Enrollment::class, $company)->whereIn('section_id', $sections->pluck('id'))->where('status', 'active')
            ->get(['section_id'])->countBy('section_id');

        return $sections->map(fn (Section $section) => [...$section->only(['id', 'unit_id', 'session_id', 'level_id', 'name']), 'students' => (int) ($counts[$section->getKey()] ?? 0)])->values()->all();
    }

    /**
     * Students studying now in a session at the given campuses (narrowed to
     * a class or a section), each with where they study, by code.
     *
     * @param  list<string>  $unitIds
     * @return list<array{student: array<string, mixed>, enrollment: array<string, mixed>}>
     */
    public function enrolledStudents(Organization $company, string $sessionId, array $unitIds, ?string $levelId = null, ?string $sectionId = null): array
    {
        $enrollments = $this->education->query(Enrollment::class, $company)->where('session_id', $sessionId)->where('status', 'active')->whereIn('unit_id', $unitIds)
            ->when($levelId !== null, fn ($query) => $query->where('level_id', $levelId))
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->get();
        $students = $this->students($company, $enrollments->pluck('student_id')->unique()->values()->all());

        return $enrollments->filter(fn (Enrollment $enrollment) => isset($students[$enrollment->student_id]))
            ->map(fn (Enrollment $enrollment) => [
                'student' => $students[$enrollment->student_id],
                'enrollment' => $enrollment->only(['id', 'unit_id', 'session_id', 'level_id', 'section_id', 'status']),
            ])->sortBy(fn (array $row) => $row['student']['code'])->values()->all();
    }

    /**
     * Each student's place among the brothers and sisters studying here
     * (students sharing a guardian, active or suspended), the first admitted
     * first: 1 for the eldest, 2 for the next… A student with no sibling here is 1.
     *
     * @param  list<string>  $studentIds
     * @return array<string, int>
     */
    public function siblingPlaces(Organization $company, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $links = $this->education->query(StudentGuardian::class, $company);
        $guardians = (clone $links)->whereIn('student_id', $studentIds)->get(['student_id', 'guardian_id']);
        $family = (clone $links)->whereIn('guardian_id', $guardians->pluck('guardian_id')->unique())->get(['student_id', 'guardian_id']);
        $current = $this->education->query(Student::class, $company)->whereKey($family->pluck('student_id')->unique())->whereIn('status', ['active', 'suspended'])
            ->get(['id', 'code', 'admitted_on'])->keyBy('id');
        $order = fn (string $id) => [$current[$id]->admitted_on?->toDateString() ?? '9999-12-31', $current[$id]->code];

        $places = [];
        foreach ($studentIds as $studentId) {
            $mine = $guardians->where('student_id', $studentId)->pluck('guardian_id');
            $siblings = $family->whereIn('guardian_id', $mine)->pluck('student_id')->push($studentId)->unique()
                ->filter(fn (string $id) => isset($current[$id]) || $id === $studentId)->values();
            if (! isset($current[$studentId])) {
                $places[$studentId] = 1;

                continue;
            }
            $sorted = $siblings->filter(fn (string $id) => isset($current[$id]))->sortBy($order)->values();
            $places[$studentId] = $sorted->search($studentId) + 1;
        }

        return $places;
    }

    /** @return array<string, mixed>|null */
    public function program(Organization $company, string $id): ?array
    {
        $program = $this->education->query(Program::class, $company)->whereKey($id)->first();

        return $program === null ? null : ['id' => $program->getKey(), 'code' => $program->code, 'progression' => $program->progression, 'name' => $program->texts('name')];
    }

    /** @return array<string, mixed> */
    private function studentRecord(Student $student): array
    {
        return [
            'id' => $student->getKey(), 'unit_id' => $student->unit_id, 'code' => $student->code, 'name' => $student->name,
            'name_local' => $student->name_local, 'program_id' => $student->program_id, 'batch_id' => $student->batch_id, 'category_id' => $student->category_id,
            'status' => $student->status, 'user_id' => $student->user_id,
        ];
    }
}
