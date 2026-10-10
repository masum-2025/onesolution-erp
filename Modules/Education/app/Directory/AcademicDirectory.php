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
     * @return array<string, array<string, mixed>>  By id; unknown ids are left out.
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
            'name_local' => $student->name_local, 'program_id' => $student->program_id, 'batch_id' => $student->batch_id,
            'status' => $student->status, 'user_id' => $student->user_id,
        ];
    }
}
