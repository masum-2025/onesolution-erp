<?php

namespace Modules\Education\Http;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Document;
use Modules\Education\Models\DocumentAsset;
use Modules\Education\Models\DocumentTemplate;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Field;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;
use Modules\Education\Services\DocumentLayout;
use Modules\Education\Services\Fields;
use Modules\Education\Services\Structure;
use Modules\Education\Services\Students;

/**
 * What the API shows of education records. Sensitive details (date of
 * birth, registration and national id numbers, sensitive own fields) only
 * when the reader may see them; a photo only as a short-lived link.
 */
class EducationPresenter
{
    public function __construct(private Fields $fields, private Students $students) {}

    /** @return array<string, mixed> */
    public function structure(string $kind, Model $record): array
    {
        $definition = Structure::definition($kind);
        $values = ['id' => $record->getKey(), ...$record->only($definition['fields'])];
        foreach ($definition['texts'] as $field) {
            $values[$field] = $record->texts($field);
            $values[$field.'_text'] = $record->{$field} === null ? null : $record->textIn($field);
        }
        if (array_key_exists('version', $record->getAttributes())) {
            $values['version'] = $record->version;
        }

        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }

    /** @return array<string, mixed> */
    public function student(Organization $company, Student $student, bool $sensitive, ?Enrollment $enrollment = null): array
    {
        return [
            'id' => $student->getKey(),
            'unit_id' => $student->unit_id,
            'code' => $student->code,
            'admission_no' => $student->admission_no,
            'name' => $student->name,
            'name_local' => $student->name_local,
            'gender' => $student->gender,
            'phone' => $student->phone,
            'email' => $student->email,
            'program_id' => $student->program_id,
            'batch_id' => $student->batch_id,
            'category_id' => $student->category_id,
            'status' => $student->status,
            'admitted_on' => $student->admitted_on->toDateString(),
            'left_on' => $student->left_on?->toDateString(),
            'left_reason' => $student->left_reason,
            'date_of_birth' => $sensitive ? $student->date_of_birth?->toDateString() : null,
            'birth_registration_no' => $sensitive ? $student->birth_registration_no : null,
            'extra' => (object) $this->fields->visible($company, 'student', $student->extra, $sensitive),
            'photo_url' => $this->students->photoLink($student),
            'enrollment' => $enrollment === null ? null : $this->enrollment($enrollment),
            'version' => $student->version,
        ];
    }

    /** @return array<string, mixed> */
    public function enrollment(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->getKey(),
            'student_id' => $enrollment->student_id,
            'unit_id' => $enrollment->unit_id,
            'session_id' => $enrollment->session_id,
            'level_id' => $enrollment->level_id,
            'section_id' => $enrollment->section_id,
            'roll_no' => $enrollment->roll_no,
            'status' => $enrollment->status,
            'started_on' => $enrollment->started_on->toDateString(),
            'ended_on' => $enrollment->ended_on?->toDateString(),
            'version' => $enrollment->version,
        ];
    }

    /** @return array<string, mixed> */
    public function guardian(Organization $company, Guardian $guardian, ?StudentGuardian $link, bool $sensitive): array
    {
        return [
            'id' => $guardian->getKey(),
            'name' => $guardian->name,
            'phone' => $guardian->phone,
            'email' => $guardian->email,
            'occupation' => $guardian->occupation,
            'national_id' => $sensitive ? $guardian->national_id : null,
            'extra' => (object) $this->fields->visible($company, 'guardian', $guardian->extra, $sensitive),
            'relation' => $link?->relation,
            'is_primary' => $link?->is_primary,
            'can_pick_up' => $link?->can_pick_up,
            'receives_notices' => $link?->receives_notices,
            'has_portal' => $guardian->user_id !== null,
            'version' => $guardian->version,
        ];
    }

    /** @return array<string, mixed> */
    public function admission(Organization $company, Admission $admission, bool $sensitive): array
    {
        $applicant = $admission->applicant;
        if (! $sensitive) {
            unset($applicant['date_of_birth']);
        }
        $applicant['extra'] = (object) $this->fields->visible($company, 'admission', $applicant['extra'] ?? [], $sensitive);
        if (! $sensitive) {
            $applicant['guardians'] = array_map(fn (array $guardian) => array_diff_key($guardian, ['national_id' => true]), (array) ($applicant['guardians'] ?? []));
        }

        return [
            'id' => $admission->getKey(),
            'unit_id' => $admission->unit_id,
            'number' => $admission->number,
            'program_id' => $admission->program_id,
            'level_id' => $admission->level_id,
            'session_id' => $admission->session_id,
            'applicant' => $applicant,
            'source' => $admission->source,
            'status' => $admission->status,
            'note' => $admission->note,
            'student_id' => $admission->student_id,
            'decided_at' => $admission->decided_at?->toIso8601String(),
            'created_at' => $admission->created_at?->toIso8601String(),
            'version' => $admission->version,
        ];
    }

    /** @return array<string, mixed> */
    public function template(DocumentTemplate $template, bool $withLayout = false): array
    {
        return [
            'id' => $template->getKey(),
            'key' => $template->key,
            'kind' => $template->kind,
            'name' => $template->texts('name'),
            'name_text' => $template->textIn('name'),
            'locale' => $template->locale,
            'page' => $template->page,
            'page_size' => DocumentLayout::dimensions($template->page),
            'status' => $template->status,
            'version' => $template->version,
            'inputs' => $template->inputs ?? [],
            ...($withLayout ? ['layout' => $template->layout] : ['elements' => count($template->layout['elements'] ?? [])]),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function asset(DocumentAsset $asset, string $url): array
    {
        return ['id' => $asset->getKey(), ...$asset->only(['kind', 'name', 'mime', 'size_bytes', 'is_active']), 'url' => $url];
    }

    /**
     * A document: its register line, and with $snapshot its design, values and links to print it.
     *
     * @param  array<string, mixed>  $snapshot  asset_links, photo_url, qr, verify_path when printing.
     * @return array<string, mixed>
     */
    public function document(Document $document, ?array $snapshot = null): array
    {
        $today = now()->toImmutable();

        return [
            'id' => $document->getKey(),
            'kind' => $document->kind,
            'title' => $document->texts('title'),
            'title_text' => $document->textIn('title'),
            'number' => $document->number,
            'student_id' => $document->student_id,
            'student_name' => $document->snapshot['summary']['student_name'] ?? null,
            'unit_id' => $document->unit_id,
            'template_id' => $document->template_id,
            'template_version' => $document->template_version,
            'locale' => $document->locale,
            'status' => $document->statusOn($today),
            'issued_on' => $document->issued_on->toDateString(),
            'valid_until' => $document->valid_until?->toDateString(),
            'issued_by' => $document->issued_by,
            'revoked_at' => $document->revoked_at?->toIso8601String(),
            'revoke_reason' => $document->revoke_reason,
            'has_sensitive' => $document->has_sensitive,
            ...($snapshot === null ? [] : [
                'page' => $document->snapshot['page'],
                'page_size' => DocumentLayout::dimensions($document->snapshot['page']),
                'layout' => $document->snapshot['layout'],
                'values' => (object) ($document->snapshot['values'] ?? []),
                ...$snapshot,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function field(Field $field): array
    {
        return ['id' => $field->getKey(), ...$this->fields->values($field), 'label_text' => $field->textIn('label'), 'version' => $field->version];
    }
}
