<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Education\Models\AcademicUnit;
use Modules\Education\Models\AcademicYear;
use Modules\Education\Models\Curriculum;
use Modules\Education\Models\CurriculumItem;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Program;
use Modules\Education\Models\Session;
use Modules\Education\Models\Subject;

/**
 * One record of the institution's structure, by kind (the address says
 * which). Names are texts in the app's languages, English required when
 * made. References are checked to be the institution's own by the service.
 */
class StructureRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $ulid = fn (bool $needed = false) => [$needed ? $required : 'sometimes', $needed ? 'string' : 'nullable', 'string', 'size:26'];
        $texts = fn (string $field, bool $needed = true, int $max = 120) => [
            $field => [$needed ? $required : 'sometimes', ...($needed ? [] : ['nullable']), 'array:'.implode(',', LanguageRegistry::codes())],
            "{$field}.*" => ['nullable', 'string', "max:{$max}"],
            ...($needed ? ["{$field}.en" => [$required, 'string', 'min:1', "max:{$max}"]] : []),
        ];
        $code = fn (bool $needed = true) => [$needed ? $required : 'sometimes', ...($needed ? [] : ['nullable']), 'string', 'regex:/^[A-Za-z0-9_-]{1,20}$/'];
        $date = [$required, 'date_format:Y-m-d'];

        $rules = match ($this->route('kind')) {
            'units' => ['parent_id' => $ulid(), 'kind' => [$required, 'in:'.implode(',', AcademicUnit::KINDS)], 'code' => $code(false), ...$texts('name')],
            'programs' => [
                'academic_unit_id' => $ulid(), 'code' => $code(), ...$texts('name'), ...$texts('level_label', false, 40), ...$texts('section_label', false, 40),
                'progression' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Program::PROGRESSIONS)],
                'periods_per_year' => ['sometimes', 'integer', 'min:1', 'max:4'],
                'total_credits_centi' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60000'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            ],
            'levels' => [
                'program_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'sequence' => [$required, 'integer', 'min:1', 'max:100'], 'code' => $code(), ...$texts('name'),
                'next_level_id' => $ulid(), 'min_age' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:80'],
            ],
            'lists' => ['kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', ListItem::KINDS)], 'key' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'], ...$texts('name'), 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']],
            'years' => ['name' => [$required, 'string', 'max:40'], 'starts_on' => $date, 'ends_on' => $date, 'status' => ['sometimes', 'in:'.implode(',', AcademicYear::STATUSES)]],
            'sessions' => [
                'academic_year_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Program::PROGRESSIONS)],
                'sequence' => ['sometimes', 'integer', 'min:1', 'max:4'], ...$texts('name', true, 60), 'starts_on' => $date, 'ends_on' => $date, 'status' => ['sometimes', 'in:'.implode(',', Session::STATUSES)],
            ],
            'sections' => [
                'unit_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'session_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'level_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
                'name' => [$required, 'string', 'max:40'], 'stream_id' => $ulid(), 'shift_id' => $ulid(), 'medium_id' => $ulid(),
                'capacity' => ['sometimes', 'integer', 'min:1', 'max:2000'], 'class_teacher_id' => $ulid(),
            ],
            'batches' => ['program_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'name' => [$required, 'string', 'max:60'], 'intake_session_id' => $ulid(), 'curriculum_id' => $ulid()],
            'subjects' => ['code' => $code(), ...$texts('name'), 'credits_centi' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'], 'kind' => ['sometimes', 'in:'.implode(',', Subject::KINDS)]],
            'curricula' => ['program_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'name' => [$required, 'string', 'max:60'], 'effective_from_year' => [$required, 'integer', 'min:1900', 'max:2200'], 'status' => ['sometimes', 'in:'.implode(',', Curriculum::STATUSES)]],
            'curriculum_items' => [
                'curriculum_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'], 'level_id' => [$required, 'string', 'size:26'], 'subject_id' => [$required, 'string', 'size:26'],
                'kind' => ['sometimes', 'in:'.implode(',', CurriculumItem::KINDS)], 'stream_id' => $ulid(), 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            ],
            'prerequisites' => ['subject_id' => ['required', 'string', 'size:26'], 'requires_subject_id' => ['required', 'string', 'size:26']],
            default => [],
        };

        // Most kinds are switched off instead of removed.
        if (! $creating && ! in_array($this->route('kind'), ['years', 'sessions', 'curricula', 'curriculum_items', 'prerequisites'], true)) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }
        // Rows with a history carry the version they were read at.
        if (! $creating && ! in_array($this->route('kind'), ['curriculum_items', 'prerequisites'], true)) {
            $rules['base_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
