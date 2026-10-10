<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Level;
use Modules\Education\Models\Program;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;

/**
 * Applications to the institution and their decisions:
 *
 *   applied -> test -> offered -> admitted
 *        \-------\--------\----> rejected | withdrawn
 *
 * Admitting makes the student (with the applicant's guardians) and their
 * first enrollment in the session and level applied for. An application
 * from another module (CRM) is made once per source record.
 */
class Admissions
{
    /** Which decision may follow which (admitted only through admit()). */
    private const NEXT = [
        'applied' => ['test', 'offered', 'rejected', 'withdrawn'],
        'test' => ['offered', 'rejected', 'withdrawn'],
        'offered' => ['rejected', 'withdrawn'],
    ];

    /** What an applicant may carry (birth registration is taken when admitted). */
    private const APPLICANT = ['name', 'name_local', 'gender', 'date_of_birth', 'phone', 'email', 'guardians', 'extra', 'previous_school'];

    public function __construct(
        private Education $education,
        private Fields $fields,
        private Numbers $numbers,
        private Students $students,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by AdmissionRequest.
     */
    public function create(Organization $company, string $unitId, array $data, ?User $actor, string $source = 'direct', ?string $sourceRef = null): Admission
    {
        if (! empty($data['op_id'])) {
            $existing = $this->education->query(Admission::class, $company)->where('op_id', $data['op_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($sourceRef !== null) {
            $existing = $this->education->query(Admission::class, $company)->where('source', $source)->where('source_ref', $sourceRef)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $this->checkPlace($company, $data['program_id'] ?? null, $data['level_id'] ?? null, $data['session_id'] ?? null);
        $applicant = array_intersect_key((array) $data['applicant'], array_flip(self::APPLICANT));
        // Required own fields of an application are asked for now (people from another module may not have them yet).
        $applicant['extra'] = $this->fields->apply($company, 'admission', (array) ($applicant['extra'] ?? []), [], $source === 'direct', 'applicant.extra');

        return $this->education->transaction($company, function () use ($company, $unitId, $data, $actor, $source, $sourceRef, $applicant) {
            $admission = new Admission;
            $admission->fill([
                'organization_id' => $company->getKey(),
                'unit_id' => $unitId,
                'number' => $this->numbers->admissionNumber($company, (int) $this->education->today($company)->format('Y')),
                'program_id' => $data['program_id'] ?? null,
                'level_id' => $data['level_id'] ?? null,
                'session_id' => $data['session_id'] ?? null,
                'applicant' => $applicant,
                'source' => $source,
                'source_ref' => $sourceRef,
                'status' => 'applied',
                'note' => $data['note'] ?? null,
                'created_by' => $actor?->getKey(),
                'op_id' => $data['op_id'] ?? null,
                'version' => 1,
            ]);
            $admission->search_text = Admission::searchText($applicant);
            $admission->save();
            $this->audit->record('education.admission_created', $admission, new: [...$admission->only(['number', 'program_id', 'level_id', 'session_id', 'source']), 'name' => $applicant['name'] ?? null], actor: $actor, organizationId: $company->getKey());

            return $admission;
        });
    }

    /**
     * Change the applicant's details or the place applied for, while undecided.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $company, Admission $admission, int $baseVersion, array $data, User $actor): Admission
    {
        return $this->education->transaction($company, function () use ($company, $admission, $baseVersion, $data, $actor) {
            $admission = $this->locked($company, $admission, $baseVersion);
            if (in_array($admission->status, Admission::FINAL, true)) {
                throw EducationException::wrongStatus($admission->status);
            }
            $old = $admission->only(['program_id', 'level_id', 'session_id', 'note']);
            $admission->fill(array_intersect_key($data, array_flip(['program_id', 'level_id', 'session_id', 'note'])));
            $this->checkPlace($company, $admission->program_id, $admission->level_id, $admission->session_id);
            if (array_key_exists('applicant', $data)) {
                $applicant = [...$admission->applicant, ...array_intersect_key((array) $data['applicant'], array_flip(self::APPLICANT))];
                $applicant['extra'] = $this->fields->apply($company, 'admission', (array) ($data['applicant']['extra'] ?? []), (array) ($admission->applicant['extra'] ?? []), false, 'applicant.extra');
                $admission->applicant = $applicant;
                $admission->search_text = Admission::searchText($applicant);
            }
            $admission->version++;
            $admission->save();
            $this->audit->record('education.admission_updated', $admission, old: $old, new: $admission->only(['program_id', 'level_id', 'session_id', 'note']), actor: $actor, organizationId: $company->getKey());

            return $admission;
        });
    }

    /** A decision other than admitting: test, offered, rejected, withdrawn. */
    public function move(Organization $company, Admission $admission, int $baseVersion, string $status, ?string $note, User $actor): Admission
    {
        return $this->education->transaction($company, function () use ($company, $admission, $baseVersion, $status, $note, $actor) {
            $admission = $this->locked($company, $admission, $baseVersion);
            if (! in_array($status, self::NEXT[$admission->status] ?? [], true)) {
                throw EducationException::wrongStatus($admission->status);
            }
            $old = $admission->status;
            $admission->forceFill([
                'status' => $status,
                'note' => $note ?? $admission->note,
                'decided_at' => in_array($status, Admission::FINAL, true) ? now() : $admission->decided_at,
                'decided_by' => $actor->getKey(),
                'version' => $admission->version + 1,
            ])->save();
            $this->audit->record('education.admission_moved', $admission, old: ['status' => $old], new: ['status' => $status, 'note' => $note], actor: $actor, organizationId: $company->getKey());

            return $admission;
        });
    }

    /**
     * Admit: the student (from the applicant, plus what is given now), their
     * guardians and first enrollment; the application is closed with them.
     *
     * @param  array<string, mixed>  $data  Validated by AdmitRequest: section_id, batch_id, category_id, birth_registration_no, admitted_on, extra.
     */
    public function admit(Organization $company, Admission $admission, int $baseVersion, array $data, User $actor): Student
    {
        return $this->education->transaction($company, function () use ($company, $admission, $baseVersion, $data, $actor) {
            $admission = $this->locked($company, $admission, $baseVersion);
            if (! isset(self::NEXT[$admission->status])) {
                throw EducationException::wrongStatus($admission->status);
            }
            if ($admission->program_id === null || $admission->level_id === null || $admission->session_id === null) {
                throw EducationException::notPlaced();
            }
            $applicant = $admission->applicant;
            if (trim((string) ($applicant['name'] ?? '')) === '') {
                throw ValidationException::withMessages(['applicant.name' => __('education::education.validation.applicant_name')]);
            }

            $student = $this->students->create($company, $admission->unit_id, [
                ...array_intersect_key($applicant, array_flip(['name', 'name_local', 'gender', 'date_of_birth', 'phone', 'email', 'guardians'])),
                ...array_intersect_key($data, array_flip(['batch_id', 'category_id', 'birth_registration_no', 'admitted_on'])),
                'program_id' => $admission->program_id,
                'admission_no' => $admission->number,
                'extra' => (array) ($data['extra'] ?? []),
                'enrollment' => ['session_id' => $admission->session_id, 'level_id' => $admission->level_id, 'section_id' => $data['section_id'] ?? null],
            ], $actor, $admission->getKey());

            $admission->forceFill([
                'status' => 'admitted',
                'student_id' => $student->getKey(),
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
                'version' => $admission->version + 1,
            ])->save();
            $this->audit->record('education.admission_admitted', $admission, new: ['student_id' => $student->getKey(), 'code' => $student->code], actor: $actor, organizationId: $company->getKey());

            return $student;
        });
    }

    /**
     * A level of the program, and a session of the kind the program is taught
     * in. No place at all is allowed (an application from CRM, placed later);
     * half a place is not.
     */
    private function checkPlace(Organization $company, ?string $programId, ?string $levelId, ?string $sessionId): void
    {
        if ($programId === null && $levelId === null && $sessionId === null) {
            return;
        }
        if ($programId === null || $levelId === null || $sessionId === null) {
            throw EducationException::notPlaced();
        }
        /** @var Program $program */
        $program = $this->education->find(Program::class, $company, $programId, 'program');
        $level = $this->education->find(Level::class, $company, $levelId, 'level');
        $session = $this->education->find(Session::class, $company, $sessionId, 'session');
        if ($level->program_id !== $program->getKey()) {
            throw EducationException::mismatch('level_id');
        }
        if ($session->kind !== $program->progression || $session->status === 'closed') {
            throw EducationException::mismatch('session_id');
        }
    }

    private function locked(Organization $company, Admission $admission, int $baseVersion): Admission
    {
        /** @var Admission $fresh */
        $fresh = $this->education->query(Admission::class, $company)->whereKey($admission->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw EducationException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
