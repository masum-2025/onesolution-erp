<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Section;
use Modules\Education\Models\Student;
use Throwable;

/**
 * Students from a spreadsheet (rows read by the browser): every row checked
 * first (commit false), then the good ones made (commit true), each with
 * its guardians and its place in the session and level chosen for the file.
 *
 * Columns: name, name_local, gender, date_of_birth, birth_registration_no,
 * phone, email, admitted_on, section (a section's name in that session and
 * level), guardian_name, guardian_phone, guardian_relation, guardian2_*;
 * any other column is an own field of students by its key. A student already
 * here (same birth registration number) or twice in the file is skipped.
 * A row made once is never made twice (its op id comes from its content).
 */
class StudentImporter
{
    public const MAX_ROWS = 2000;

    private const KNOWN = [
        'name', 'name_local', 'gender', 'date_of_birth', 'birth_registration_no', 'phone', 'email', 'admitted_on', 'section',
        'guardian_name', 'guardian_phone', 'guardian_relation', 'guardian2_name', 'guardian2_phone', 'guardian2_relation',
    ];

    /** Columns only people allowed to see private details may bring. */
    public const SENSITIVE = ['date_of_birth', 'birth_registration_no'];

    public function __construct(
        private Education $education,
        private Students $students,
        private Fields $fields,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{program_id: string, session_id: string, level_id: string, section_id?: string|null}  $place
     * @param  list<array<string, string|null>>  $rows
     * @return array{rows: list<array{line: int, status: string, errors: array<string, string>, student_id: string|null}>, ok: int, duplicates: int, errors: int, made: int}
     */
    public function import(Organization $company, string $unitId, array $place, array $rows, bool $commit, User $actor): array
    {
        /** @var Level $level */
        $level = $this->education->find(Level::class, $company, $place['level_id'], 'level');
        $sections = $this->education->query(Section::class, $company)->where('session_id', $place['session_id'])->where('level_id', $level->getKey())
            ->where('unit_id', $unitId)->where('is_active', true)->get()->keyBy(fn (Section $section) => mb_strtolower($section->name));
        $room = $sections->mapWithKeys(fn (Section $section) => [$section->getKey() => $section->capacity - $this->education->query(Enrollment::class, $company)
            ->where('section_id', $section->getKey())->where('status', 'active')->count()])->all();
        $lists = $this->education->query(ListItem::class, $company)->whereIn('kind', ['gender', 'relation'])->get()->groupBy('kind')
            ->map(fn ($items) => $items->pluck('key')->all());
        $country = $this->education->country($company);

        $result = ['rows' => [], 'ok' => 0, 'duplicates' => 0, 'errors' => 0, 'made' => 0];
        $seen = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $index => $row) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);
            $line = $index + 2;
            $errors = [];
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || mb_strlen($name) > 150) {
                $errors['name'] = __('education::education.validation.import_name');
            }
            if (($row['gender'] ?? '') !== '' && ! in_array($row['gender'], $lists['gender'] ?? [], true)) {
                $errors['gender'] = __('education::education.validation.reference');
            }
            foreach (['date_of_birth', 'admitted_on'] as $column) {
                if (($row[$column] ?? '') !== '' && self::date($row[$column]) === null) {
                    $errors[$column] = __('education::education.validation.field_date');
                }
            }
            if (($row['phone'] ?? '') !== '' && PhoneNumber::normalize((string) $row['phone'], $country) === null) {
                $errors['phone'] = __('education::education.validation.phone');
            }
            if (($row['email'] ?? '') !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = __('validation.email', ['attribute' => 'email']);
            }

            $guardians = [];
            foreach (['guardian', 'guardian2'] as $prefix) {
                if (($row["{$prefix}_name"] ?? '') === '' && ($row["{$prefix}_phone"] ?? '') === '') {
                    continue;
                }
                $relation = ($row["{$prefix}_relation"] ?? '') ?: 'guardian';
                if (! in_array($relation, $lists['relation'] ?? [], true)) {
                    $errors["{$prefix}_relation"] = __('education::education.validation.reference');
                }
                if (($row["{$prefix}_phone"] ?? '') !== '' && PhoneNumber::normalize((string) $row["{$prefix}_phone"], $country) === null) {
                    $errors["{$prefix}_phone"] = __('education::education.validation.phone');
                }
                $guardians[] = ['name' => $row["{$prefix}_name"] ?? null, 'phone' => $row["{$prefix}_phone"] ?? null, 'relation' => $relation];
            }

            $sectionId = $place['section_id'] ?? null;
            if (($row['section'] ?? '') !== '') {
                $sectionId = $sections->get(mb_strtolower((string) $row['section']))?->getKey();
                if ($sectionId === null) {
                    $errors['section'] = __('education::education.validation.import_section', ['name' => $row['section']]);
                }
            }

            $extra = array_filter(array_diff_key($row, array_flip(self::KNOWN)), fn ($value) => $value !== '' && $value !== null);
            $errors += $this->fields->check($company, 'student', $extra, [], true)['errors'];

            $hash = Student::hashOf($row['birth_registration_no'] ?? null);
            $key = $hash ?? 'name:'.mb_strtolower($name).'|'.($guardians[0]['phone'] ?? '');
            $duplicate = isset($seen[$key])
                || ($hash !== null && $this->education->query(Student::class, $company)->where('birth_registration_hash', $hash)->whereIn('status', ['active', 'suspended'])->exists());
            $seen[$key] = true;

            if ($errors === [] && ! $duplicate && $sectionId !== null) {
                if (($room[$sectionId] ?? 0) <= 0) {
                    $errors['section'] = __('education::education.errors.section_full', ['capacity' => $sections->firstWhere('id', $sectionId)?->capacity ?? 0]);
                } else {
                    $room[$sectionId]--;
                }
            }

            $status = $errors !== [] ? 'error' : ($duplicate ? 'duplicate' : 'ok');
            $studentId = null;
            if ($status === 'ok' && $commit) {
                try {
                    $studentId = $this->students->create($company, $unitId, [
                        ...array_intersect_key($row, array_flip(['name', 'name_local', 'phone', 'email', 'birth_registration_no'])),
                        'gender' => ($row['gender'] ?? '') ?: null,
                        'date_of_birth' => self::date($row['date_of_birth'] ?? null),
                        'admitted_on' => self::date($row['admitted_on'] ?? null),
                        'program_id' => $place['program_id'],
                        'extra' => $extra,
                        'guardians' => $guardians,
                        'enrollment' => ['session_id' => $place['session_id'], 'level_id' => $level->getKey(), 'section_id' => $sectionId],
                        'op_id' => 'import:'.substr(sha1(json_encode([$place, $row])), 0, 40),
                    ], $actor)->getKey();
                    $result['made']++;
                } catch (TenancyException $problem) {
                    // Something that changed since the check (a section filled up): this row only.
                    $status = 'error';
                    $errors['row'] = $problem->userMessage();
                } catch (ValidationException $problem) {
                    $status = 'error';
                    $errors += array_map(fn (array $messages) => $messages[0], $problem->errors());
                }
            }
            $result[$status === 'ok' ? 'ok' : ($status === 'duplicate' ? 'duplicates' : 'errors')]++;
            $result['rows'][] = ['line' => $line, 'status' => $status, 'errors' => $errors, 'student_id' => $studentId];
        }

        if ($commit) {
            $this->audit->record('education.students_imported', null, new: ['made' => $result['made'], 'duplicates' => $result['duplicates'], 'errors' => $result['errors']], actor: $actor, organizationId: $company->getKey());
        }

        return $result;
    }

    /** YYYY-MM-DD, or DD/MM/YYYY as spreadsheets in Bangladesh write it. */
    private static function date(?string $text): ?string
    {
        $text = trim((string) $text);
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== null && $date->format(ltrim($format, '!')) === $text) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
