<?php

namespace Modules\Education\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Education\Models\Batch;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Field;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\Level;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;

/**
 * The text each placeholder of a design prints for a student, in the
 * design's language: names in that language, dates as day/month/year, and
 * in Bangla the date and roll digits as Bangla digits (codes and numbers
 * people type stay as they are). The class is the student's current one,
 * else their last (a transfer certificate after leaving).
 */
class DocumentValues
{
    private const BANGLA_DIGITS = ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'];

    public function __construct(private Education $education, private Fields $fields) {}

    /**
     * @param  array<string, mixed>  $template  kind, locale, layout, inputs
     * @param  array<string, string|null>  $inputs  What was asked when issuing.
     * @param  array{number?: string, date?: CarbonImmutable, valid_until?: CarbonImmutable|null}  $document
     * @return array<string, string>  placeholder => text, only for the placeholders the design uses.
     */
    public function for(Organization $company, Student $student, array $template, array $inputs, array $document): array
    {
        $locale = $template['locale'];
        $used = DocumentLayout::used($template['layout']);
        $enrollment = $this->enrollment($company, $student);
        $find = fn (string $model, ?string $id) => $id === null ? null : $this->education->query($model, $company)->whereKey($id)->first();
        $section = $find(Section::class, $enrollment?->section_id);
        $list = fn (?string $id) => $find(ListItem::class, $id)?->textIn('name', $locale) ?? '';

        $values = [];
        foreach ($used as $key) {
            $values[$key] = match (true) {
                $key === 'student.name' => $student->name,
                $key === 'student.name_local' => (string) $student->name_local,
                $key === 'student.code' => $student->code,
                $key === 'student.admission_no' => (string) $student->admission_no,
                $key === 'student.gender' => $student->gender === null ? '' : ($this->education->query(ListItem::class, $company)->where('kind', 'gender')->where('key', $student->gender)->first()?->textIn('name', $locale) ?? $student->gender),
                $key === 'student.phone' => (string) $student->phone,
                $key === 'student.date_of_birth' => $this->date($student->date_of_birth, $locale),
                $key === 'student.birth_registration_no' => (string) $student->birth_registration_no,
                $key === 'student.admitted_on' => $this->date($student->admitted_on, $locale),
                $key === 'student.left_on' => $this->date($student->left_on, $locale),
                $key === 'student.left_reason' => (string) $student->left_reason,
                $key === 'student.status' => __("education::education.document.statuses.{$student->status}", [], $locale),
                str_starts_with($key, 'guardian.') => $this->guardian($company, $student, substr($key, 9), $locale),
                $key === 'program' => $find(Program::class, $student->program_id)?->textIn('name', $locale) ?? '',
                $key === 'level' => $find(Level::class, $enrollment?->level_id)?->textIn('name', $locale) ?? '',
                $key === 'section' => (string) $section?->name,
                $key === 'roll' => $enrollment?->roll_no === null ? '' : $this->digits((string) $enrollment->roll_no, $locale),
                $key === 'session' => $find(Session::class, $enrollment?->session_id)?->textIn('name', $locale) ?? '',
                $key === 'batch' => (string) $find(Batch::class, $student->batch_id)?->name,
                $key === 'category' => $list($student->category_id),
                $key === 'shift' => $list($section?->shift_id),
                $key === 'medium' => $list($section?->medium_id),
                $key === 'stream' => $list($section?->stream_id),
                $key === 'institution.name' => $company->displayName($locale),
                $key === 'document.number' => (string) ($document['number'] ?? ''),
                $key === 'document.date' => $this->date($document['date'] ?? null, $locale),
                $key === 'document.valid_until' => $this->date($document['valid_until'] ?? null, $locale),
                str_starts_with($key, 'field.') => $this->field($company, $student, substr($key, 6), $locale),
                str_starts_with($key, 'input.') => trim((string) ($inputs[substr($key, 6)] ?? '')),
                default => '',
            };
        }

        return $values;
    }

    /**
     * What is asked when issuing, checked against the design's inputs.
     *
     * @param  list<array<string, mixed>>  $declared
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    public function inputs(array $declared, array $given): array
    {
        $clean = [];
        $errors = [];
        foreach ($declared as $input) {
            $value = trim((string) ($given[$input['key']] ?? ''));
            if ($input['required'] && $value === '') {
                $errors["inputs.{$input['key']}"] = __('education::education.validation.field_required', ['field' => $input['label']['en'] ?? $input['key']]);
            }
            if (mb_strlen($value) > 1000) {
                $errors["inputs.{$input['key']}"] = __('validation.max.string', ['attribute' => $input['key'], 'max' => 1000]);
            }
            $clean[$input['key']] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * What the public QR check may show of the student, as at issue: their
     * name and their class in the document's language.
     *
     * @return array{student_name: string, level: string}
     */
    public function summary(Organization $company, Student $student, string $locale): array
    {
        $levelId = $this->enrollment($company, $student)?->level_id;
        $level = $levelId === null ? null : $this->education->query(Level::class, $company)->whereKey($levelId)->first();
        $program = $level === null ? null : $this->education->query(Program::class, $company)->whereKey($level->program_id)->first();

        return [
            'student_name' => $locale === 'bn' && $student->name_local ? $student->name_local : $student->name,
            'level' => trim(($program?->textIn('name', $locale) ?? '').($level === null ? '' : ' · '.$level->textIn('name', $locale)), ' ·'),
        ];
    }

    /** The student's current enrollment, else their last one. */
    public function enrollment(Organization $company, Student $student): ?Enrollment
    {
        $of = fn () => $this->education->query(Enrollment::class, $company)->where('student_id', $student->getKey())->orderByDesc('started_on');

        return $of()->where('status', 'active')->first() ?? $of()->first();
    }

    /** Day/month/year, in Bangla with Bangla digits. */
    public function date(?\DateTimeInterface $date, string $locale): string
    {
        return $date === null ? '' : $this->digits($date->format('d/m/Y'), $locale);
    }

    public function digits(string $text, string $locale): string
    {
        return $locale === 'bn' ? strtr($text, self::BANGLA_DIGITS) : $text;
    }

    private function guardian(Organization $company, Student $student, string $which, string $locale): string
    {
        $links = $this->education->query(StudentGuardian::class, $company)->where('student_id', $student->getKey())->get();
        $link = match ($which) {
            'father', 'mother' => $links->firstWhere('relation', $which),
            default => $links->firstWhere('is_primary', true) ?? $links->first(),
        };
        $guardian = $link === null ? null : $this->education->query(Guardian::class, $company)->whereKey($link->guardian_id)->first();
        if ($guardian === null) {
            return '';
        }

        return $which === 'primary_phone' ? (string) $guardian->phone : $guardian->name;
    }

    private function field(Organization $company, Student $student, string $key, string $locale): string
    {
        /** @var Field|null $field */
        $field = $this->fields->of($company, 'student')->firstWhere('key', $key);
        $value = $student->extra[$key] ?? null;
        if ($field === null || $value === null || $value === '') {
            return '';
        }
        $option = fn ($chosen) => collect($field->options ?? [])->firstWhere('value', $chosen);
        $label = fn ($chosen) => ($found = $option($chosen)) === null ? (string) $chosen : (string) ($found['label'][$locale] ?? $found['label']['en'] ?? $chosen);

        return match ($field->type) {
            'yes_no' => __($value ? 'education::education.document.yes' : 'education::education.document.no', [], $locale),
            'date' => $this->date(CarbonImmutable::parse((string) $value), $locale),
            'choice' => $label($value),
            'multi_choice' => implode(', ', array_map($label, (array) $value)),
            default => (string) $value,
        };
    }
}
