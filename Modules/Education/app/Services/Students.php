<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Education\Events\StudentAdmitted;
use Modules\Education\Events\StudentLeft;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Batch;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Program;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;

/**
 * Students and their guardians.
 *
 * - A student gets a code from the rule education.student_code_format, at a
 *   campus, in a program (and batch); a birth registration number already
 *   held by another student here is refused, naming that student's code.
 * - Sensitive details (date of birth, registration number, sensitive own
 *   fields) are encrypted or kept apart and audited without their values.
 * - One phone, one guardian: a guardian already here (by phone) is linked,
 *   not made twice, so siblings share their parents. One guardian per
 *   student is the primary contact.
 * - A student is never deleted: they leave (with a reason) or graduate.
 * - Creating with an op id is safe to retry.
 */
class Students
{
    public const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const PHOTO_MAX_KB = 2048;

    public const LINK_MINUTES = 5;

    /** Details people set directly. */
    private const FIELDS = ['name', 'name_local', 'gender', 'phone', 'email', 'program_id', 'batch_id', 'category_id', 'admission_no'];

    /** Details shown and changed only with education.view_sensitive. */
    public const SENSITIVE = ['date_of_birth', 'birth_registration_no'];

    public function __construct(
        private Education $education,
        private Fields $fields,
        private Numbers $numbers,
        private Enrollments $enrollments,
        private AuditLogger $audit,
    ) {}

    /**
     * A new student, their guardians and (when given) their first enrollment.
     *
     * @param  array<string, mixed>  $data  Validated by StudentRequest.
     */
    public function create(Organization $company, string $unitId, array $data, User $actor, ?string $admissionId = null): Student
    {
        if (! empty($data['op_id'])) {
            $existing = $this->education->query(Student::class, $company)->where('op_id', $data['op_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $extra = $this->fields->apply($company, 'student', (array) ($data['extra'] ?? []), [], true);

        return $this->education->transaction($company, function () use ($company, $unitId, $data, $actor, $admissionId, $extra) {
            /** @var Program $program */
            $program = $this->education->find(Program::class, $company, $data['program_id'] ?? null, 'program');
            $admittedOn = isset($data['admitted_on']) ? CarbonImmutable::parse($data['admitted_on']) : $this->education->today($company);

            $student = new Student;
            $student->fill([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $company->getKey(),
                'unit_id' => $unitId,
                'status' => 'active',
                'admitted_on' => $admittedOn->toDateString(),
                'extra' => $extra ?: null,
                'created_by' => $actor->getKey(),
                'op_id' => $data['op_id'] ?? null,
                'version' => 1,
            ]);
            $student->phone = $this->phone($company, $data['phone'] ?? null, 'phone');
            $student->email = self::email($data['email'] ?? null);
            $this->sensitive($company, $student, $data);
            $this->checkReferences($company, $student);
            $student->code = $this->numbers->studentCode($company, (int) $admittedOn->format('Y'), $program->code);
            $student->save();

            foreach ((array) ($data['guardians'] ?? []) as $guardian) {
                $this->link($company, $student, $guardian, $actor, audit: false);
            }
            if (! empty($data['enrollment']['session_id'])) {
                $this->enrollments->enroll($company, $student, $data['enrollment']['session_id'], $data['enrollment']['level_id'], $data['enrollment']['section_id'] ?? null, $admittedOn);
            }

            $this->audit->record('education.student_created', $student, new: $this->auditValues($student), actor: $actor, organizationId: $company->getKey());
            $event = new StudentAdmitted($company->getKey(), $student->getKey(), $admissionId);
            DB::afterCommit(fn () => event($event));

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change (sensitive ones only when allowed; the controller checks).
     */
    public function update(Organization $company, Student $student, int $baseVersion, array $data, User $actor): Student
    {
        return $this->education->transaction($company, function () use ($company, $student, $baseVersion, $data, $actor) {
            $student = $this->locked($company, $student, $baseVersion);
            $old = $this->auditValues($student);
            $student->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('phone', $data)) {
                $student->phone = $this->phone($company, $data['phone'], 'phone');
            }
            if (array_key_exists('email', $data)) {
                $student->email = self::email($data['email']);
            }
            $this->sensitive($company, $student, $data);
            if (array_key_exists('extra', $data)) {
                $student->extra = $this->fields->apply($company, 'student', (array) $data['extra'], (array) ($student->extra ?? []), false) ?: null;
            }
            $this->checkReferences($company, $student);
            $student->version++;
            $student->save();
            $this->audit->record('education.student_updated', $student, old: $old, new: $this->auditValues($student), actor: $actor, organizationId: $company->getKey());

            return $student;
        });
    }

    /** The student leaves (or graduates): their enrollment ends, their record stays. */
    public function leave(Organization $company, Student $student, int $baseVersion, string $status, string $reason, ?string $on, User $actor): Student
    {
        return $this->education->transaction($company, function () use ($company, $student, $baseVersion, $status, $reason, $on, $actor) {
            $student = $this->locked($company, $student, $baseVersion);
            if (! in_array($student->status, ['active', 'suspended'], true)) {
                throw EducationException::wrongStatus($student->status);
            }
            $day = $on !== null ? CarbonImmutable::parse($on) : $this->education->today($company);
            $student->forceFill(['status' => $status, 'left_on' => $day->toDateString(), 'left_reason' => $reason, 'version' => $student->version + 1])->save();
            $this->enrollments->end($company, $student->getKey(), $status === 'graduated' ? 'graduated' : 'left', $day);
            $this->audit->record('education.student_left', $student, new: ['status' => $status, 'reason' => $reason, 'on' => $day->toDateString()], actor: $actor, organizationId: $company->getKey());
            $event = new StudentLeft($company->getKey(), $student->getKey(), $status);
            DB::afterCommit(fn () => event($event));

            return $student;
        });
    }

    /**
     * Link a guardian to a student: one already here (by id, or by phone) or
     * a new one, with the relation and what they may do.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(Organization $company, Student $student, array $data, User $actor, bool $audit = true): StudentGuardian
    {
        return $this->education->transaction($company, function () use ($company, $student, $data, $actor, $audit) {
            $relation = (string) ($data['relation'] ?? '');
            if (! $this->education->query(ListItem::class, $company)->where('kind', 'relation')->where('key', $relation)->exists()) {
                throw ValidationException::withMessages(['relation' => __('education::education.validation.reference')]);
            }

            $guardian = ! empty($data['guardian_id'])
                ? $this->education->find(Guardian::class, $company, $data['guardian_id'], 'guardian')
                : $this->guardianFor($company, $data);

            $link = $this->education->query(StudentGuardian::class, $company)->where('student_id', $student->getKey())->where('guardian_id', $guardian->getKey())->first() ?? new StudentGuardian;
            $first = ! $this->education->query(StudentGuardian::class, $company)->where('student_id', $student->getKey())->exists();
            $link->fill([
                'organization_id' => $company->getKey(),
                'student_id' => $student->getKey(),
                'guardian_id' => $guardian->getKey(),
                'relation' => $relation,
                'is_primary' => (bool) ($data['is_primary'] ?? $first),
                'can_pick_up' => (bool) ($data['can_pick_up'] ?? true),
                'receives_notices' => (bool) ($data['receives_notices'] ?? true),
            ]);
            $link->save();
            if ($link->is_primary) {
                $this->education->query(StudentGuardian::class, $company)->where('student_id', $student->getKey())->whereKeyNot($link->getKey())->update(['is_primary' => false]);
            }
            if ($audit) {
                $this->audit->record('education.guardian_linked', $link, new: $link->only(['student_id', 'guardian_id', 'relation', 'is_primary']), actor: $actor, organizationId: $company->getKey());
            }

            return $link;
        });
    }

    public function unlink(Organization $company, Student $student, string $guardianId, User $actor): void
    {
        $this->education->transaction($company, function () use ($company, $student, $guardianId, $actor) {
            $link = $this->education->query(StudentGuardian::class, $company)->where('student_id', $student->getKey())->where('guardian_id', $guardianId)->first() ?? throw EducationException::notFound('guardian');
            $this->audit->record('education.guardian_unlinked', $link, old: $link->only(['student_id', 'guardian_id', 'relation']), actor: $actor, organizationId: $company->getKey());
            $link->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGuardian(Organization $company, Guardian $guardian, int $baseVersion, array $data, User $actor): Guardian
    {
        return $this->education->transaction($company, function () use ($company, $guardian, $baseVersion, $data, $actor) {
            /** @var Guardian $guardian */
            $guardian = $this->education->query(Guardian::class, $company)->whereKey($guardian->getKey())->lockForUpdate()->firstOrFail();
            if ($guardian->version !== $baseVersion) {
                throw EducationException::versionConflict(['version' => $guardian->version]);
            }
            $old = $guardian->only(['name', 'phone', 'email', 'occupation']);
            $guardian->fill(array_intersect_key($data, array_flip(['name', 'occupation'])));
            if (array_key_exists('phone', $data)) {
                $phone = $this->phone($company, $data['phone'], 'phone');
                if ($phone !== null && $this->education->query(Guardian::class, $company)->where('phone', $phone)->whereKeyNot($guardian->getKey())->exists()) {
                    throw ValidationException::withMessages(['phone' => __('education::education.validation.guardian_phone_taken')]);
                }
                $guardian->phone = $phone;
            }
            if (array_key_exists('email', $data)) {
                $guardian->email = self::email($data['email']);
            }
            if (array_key_exists('national_id', $data)) {
                $guardian->national_id = ($data['national_id'] ?? '') === '' ? null : trim((string) $data['national_id']);
            }
            if (array_key_exists('extra', $data)) {
                $guardian->extra = $this->fields->apply($company, 'guardian', (array) $data['extra'], (array) ($guardian->extra ?? []), false) ?: null;
            }
            $guardian->version++;
            $guardian->save();
            $this->audit->record('education.guardian_updated', $guardian, old: $old, new: [...$guardian->only(['name', 'phone', 'email', 'occupation']), 'national_id_changed' => array_key_exists('national_id', $data)], actor: $actor, organizationId: $company->getKey());

            return $guardian;
        });
    }

    public function storePhoto(Organization $company, Student $student, UploadedFile $file, User $actor): Student
    {
        if (! in_array($file->getMimeType(), self::PHOTO_TYPES, true) || $file->getSize() > self::PHOTO_MAX_KB * 1024) {
            throw EducationException::badPhoto();
        }
        $path = $file->storeAs("education/{$company->getKey()}/{$student->getKey()}", Str::ulid().'.'.($file->guessExtension() ?? 'jpg'), 'local');

        return $this->education->transaction($company, function () use ($company, $student, $path, $actor) {
            /** @var Student $student */
            $student = $this->education->query(Student::class, $company)->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $old = $student->photo_path;
            $student->forceFill(['photo_path' => $path, 'version' => $student->version + 1])->save();
            if ($old !== null) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($old));
            }
            $this->audit->record('education.student_photo_changed', $student, actor: $actor, organizationId: $company->getKey());

            return $student;
        });
    }

    /** A link that shows the photo for a few minutes (routes/web.php of the module). */
    public function photoLink(Student $student): ?string
    {
        return $student->photo_path === null ? null : URL::temporarySignedRoute('education.photo', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $student->organization_id,
            'student' => $student->getKey(),
            // A new photo is a new address: browsers never show the old one.
            'v' => substr(md5($student->photo_path), 0, 8),
        ], absolute: false);
    }

    /**
     * A typed number as the institution keeps it (E.164), or null when empty.
     */
    public function phone(Organization $company, mixed $typed, string $field): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }

        return PhoneNumber::normalize($typed, $this->education->country($company))
            ?? throw ValidationException::withMessages([$field => __('education::education.validation.phone')]);
    }

    /**
     * The guardian with this phone here, or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardianFor(Organization $company, array $data): Guardian
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = $this->phone($company, $data['phone'] ?? null, 'phone');
        if ($phone !== null && ($found = $this->education->query(Guardian::class, $company)->where('phone', $phone)->first()) !== null) {
            return $found;
        }
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('education::education.validation.guardian_name')]);
        }

        $guardian = new Guardian;
        $guardian->fill([
            'organization_id' => $company->getKey(),
            'name' => mb_substr($name, 0, 150),
            'phone' => $phone,
            'email' => self::email($data['email'] ?? null),
            'occupation' => ($data['occupation'] ?? null) ?: null,
            'national_id' => ($data['national_id'] ?? '') === '' ? null : trim((string) $data['national_id']),
            'extra' => $this->fields->apply($company, 'guardian', (array) ($data['extra'] ?? []), [], true) ?: null,
            'version' => 1,
        ]);
        $guardian->save();

        return $guardian;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sensitive(Organization $company, Student $student, array $data): void
    {
        if (array_key_exists('date_of_birth', $data)) {
            $student->date_of_birth = $data['date_of_birth'] ?: null;
        }
        if (! array_key_exists('birth_registration_no', $data)) {
            return;
        }
        $number = trim((string) $data['birth_registration_no']);
        $hash = Student::hashOf($number);
        if ($hash !== null) {
            $other = $this->education->query(Student::class, $company)->where('birth_registration_hash', $hash)
                ->whereIn('status', ['active', 'suspended'])
                ->when($student->exists, fn ($query) => $query->whereKeyNot($student->getKey()))->first();
            if ($other !== null) {
                throw EducationException::duplicateStudent($other->code);
            }
        }
        $student->birth_registration_no = $number === '' ? null : $number;
        $student->birth_registration_hash = $hash;
    }

    private function checkReferences(Organization $company, Student $student): void
    {
        if ($student->batch_id !== null) {
            $batch = $this->education->find(Batch::class, $company, $student->batch_id, 'batch');
            if ($batch->program_id !== $student->program_id) {
                throw EducationException::mismatch('batch_id');
            }
        }
        foreach (['category_id' => 'category'] as $field => $kind) {
            if ($student->{$field} !== null && ! $this->education->query(ListItem::class, $company)->whereKey($student->{$field})->where('kind', $kind)->exists()) {
                throw ValidationException::withMessages([$field => __('education::education.validation.reference')]);
            }
        }
        if ($student->gender !== null && ! $this->education->query(ListItem::class, $company)->where('kind', 'gender')->where('key', $student->gender)->exists()) {
            throw ValidationException::withMessages(['gender' => __('education::education.validation.reference')]);
        }
        $this->education->find(Program::class, $company, $student->program_id, 'program');
    }

    private function locked(Organization $company, Student $student, int $baseVersion): Student
    {
        /** @var Student $fresh */
        $fresh = $this->education->query(Student::class, $company)->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw EducationException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /**
     * What the audit keeps: never the sensitive values, only that they changed.
     *
     * @return array<string, mixed>
     */
    private function auditValues(Student $student): array
    {
        return [
            ...$student->only(['code', 'name', 'name_local', 'gender', 'phone', 'email', 'program_id', 'batch_id', 'category_id', 'status', 'unit_id']),
            'date_of_birth_set' => $student->date_of_birth !== null,
            'birth_registration_hash' => $student->birth_registration_hash === null ? null : substr($student->birth_registration_hash, 0, 8),
        ];
    }

    private static function email(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
