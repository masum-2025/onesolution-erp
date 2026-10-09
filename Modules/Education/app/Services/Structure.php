<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\AcademicUnit;
use Modules\Education\Models\AcademicYear;
use Modules\Education\Models\Batch;
use Modules\Education\Models\Curriculum;
use Modules\Education\Models\CurriculumItem;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Prerequisite;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Subject;

/**
 * The institution's structure, the same way for every kind: faculties and
 * departments, programs and their levels, its own lists, years and the
 * sessions taught in them, sections, batches, subjects and curricula.
 *
 * Every reference must be the institution's own and fit (a level of the
 * same program, a section's level taught in that kind of session, a campus
 * of this institution). Things people learn in are switched off, never
 * removed; only curriculum entries and prerequisites (no history of their
 * own) can be taken out. Every change is audited.
 */
class Structure
{
    /**
     * kind => model, fields people set, translatable fields, references (field => model), unique fields.
     *
     * @var array<string, array{model: class-string<Model>, fields: list<string>, texts: list<string>, refs: array<string, class-string<Model>>, unique: list<string>, removable?: bool}>
     */
    public const KINDS = [
        'units' => ['model' => AcademicUnit::class, 'fields' => ['parent_id', 'kind', 'code', 'is_active'], 'texts' => ['name'], 'refs' => ['parent_id' => AcademicUnit::class], 'unique' => ['code']],
        'programs' => ['model' => Program::class, 'fields' => ['academic_unit_id', 'code', 'progression', 'periods_per_year', 'total_credits_centi', 'is_active', 'sort_order'], 'texts' => ['name', 'level_label', 'section_label'], 'refs' => ['academic_unit_id' => AcademicUnit::class], 'unique' => ['code']],
        'levels' => ['model' => Level::class, 'fields' => ['program_id', 'sequence', 'code', 'next_level_id', 'min_age', 'is_active'], 'texts' => ['name'], 'refs' => ['program_id' => Program::class, 'next_level_id' => Level::class], 'unique' => []],
        'lists' => ['model' => ListItem::class, 'fields' => ['kind', 'key', 'is_active', 'sort_order'], 'texts' => ['name'], 'refs' => [], 'unique' => []],
        'years' => ['model' => AcademicYear::class, 'fields' => ['name', 'starts_on', 'ends_on', 'status'], 'texts' => [], 'refs' => [], 'unique' => ['name']],
        'sessions' => ['model' => Session::class, 'fields' => ['academic_year_id', 'kind', 'sequence', 'starts_on', 'ends_on', 'status'], 'texts' => ['name'], 'refs' => ['academic_year_id' => AcademicYear::class], 'unique' => []],
        'sections' => ['model' => Section::class, 'fields' => ['unit_id', 'session_id', 'level_id', 'name', 'stream_id', 'shift_id', 'medium_id', 'capacity', 'class_teacher_id', 'is_active'], 'texts' => [], 'refs' => ['session_id' => Session::class, 'level_id' => Level::class, 'stream_id' => ListItem::class, 'shift_id' => ListItem::class, 'medium_id' => ListItem::class], 'unique' => []],
        'batches' => ['model' => Batch::class, 'fields' => ['program_id', 'name', 'intake_session_id', 'curriculum_id', 'is_active'], 'texts' => [], 'refs' => ['program_id' => Program::class, 'intake_session_id' => Session::class, 'curriculum_id' => Curriculum::class], 'unique' => []],
        'subjects' => ['model' => Subject::class, 'fields' => ['code', 'credits_centi', 'kind', 'is_active'], 'texts' => ['name'], 'refs' => [], 'unique' => ['code']],
        'curricula' => ['model' => Curriculum::class, 'fields' => ['program_id', 'name', 'effective_from_year', 'status'], 'texts' => [], 'refs' => ['program_id' => Program::class], 'unique' => []],
        'curriculum_items' => ['model' => CurriculumItem::class, 'fields' => ['curriculum_id', 'level_id', 'subject_id', 'kind', 'stream_id', 'sort_order'], 'texts' => [], 'refs' => ['curriculum_id' => Curriculum::class, 'level_id' => Level::class, 'subject_id' => Subject::class, 'stream_id' => ListItem::class], 'unique' => [], 'removable' => true],
        'prerequisites' => ['model' => Prerequisite::class, 'fields' => ['subject_id', 'requires_subject_id'], 'texts' => [], 'refs' => ['subject_id' => Subject::class, 'requires_subject_id' => Subject::class], 'unique' => [], 'removable' => true],
    ];

    /** Which list kind a section's or item's reference must be. */
    private const LIST_KINDS = ['stream_id' => 'stream', 'shift_id' => 'shift', 'medium_id' => 'medium'];

    /** Filters a listing may use (exact match on these columns). */
    private const FILTERS = ['kind', 'program_id', 'academic_year_id', 'session_id', 'level_id', 'unit_id', 'curriculum_id', 'subject_id', 'status', 'is_active', 'parent_id'];

    public function __construct(
        private Education $education,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return array{model: class-string<Model>, fields: list<string>, texts: list<string>, refs: array<string, class-string<Model>>, unique: list<string>, removable?: bool} */
    public static function definition(string $kind): array
    {
        return self::KINDS[$kind] ?? throw EducationException::notFound('kind');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $unitIds  Campuses whose sections may be listed (null: all).
     * @return Collection<int, Model>
     */
    public function list(Organization $company, string $kind, array $filters = [], ?array $unitIds = null): Collection
    {
        $definition = self::definition($kind);
        $query = $this->education->query($definition['model'], $company);
        foreach (array_intersect_key($filters, array_flip(self::FILTERS)) as $column => $value) {
            if ($value !== null && $value !== '') {
                $query->where($column, $column === 'is_active' ? filter_var($value, FILTER_VALIDATE_BOOL) : $value);
            }
        }
        if ($kind === 'sections' && $unitIds !== null) {
            $query->whereIn('unit_id', $unitIds);
        }

        return $this->ordered($query, $kind)->limit(2000)->get();
    }

    /**
     * Make ($record null) or change a record of a kind.
     *
     * @param  array<string, mixed>  $data  Validated by StructureRequest.
     */
    public function save(Organization $company, string $kind, ?Model $record, ?int $baseVersion, array $data, User $actor): Model
    {
        $definition = self::definition($kind);

        return $this->education->transaction($company, function () use ($company, $kind, $definition, $record, $baseVersion, $data, $actor) {
            if ($record !== null) {
                $record = $this->education->query($definition['model'], $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if (array_key_exists('version', $record->getAttributes()) && $record->version !== $baseVersion) {
                    throw EducationException::versionConflict(['version' => $record->version]);
                }
                $old = $this->values($record, $definition);
            } else {
                $record = new $definition['model'];
                $record->organization_id = $company->getKey();
                $old = null;
            }

            $record->fill(array_intersect_key($data, array_flip($definition['fields'])));
            foreach ($definition['texts'] as $field) {
                if (array_key_exists($field, $data)) {
                    $record->putTexts($field, $data[$field]);
                }
            }
            if ($kind === 'sections' && $record->capacity === null) {
                $record->capacity = (int) $this->rules->get('education.section_capacity_default', $this->contexts->forOrganization($company));
            }

            $this->checkReferences($company, $definition, $record);
            $this->checkUnique($company, $kind, $definition, $record);
            $this->checkKind($company, $kind, $record);

            if ($old !== null && array_key_exists('version', $record->getAttributes())) {
                $record->version++;
            }
            $record->save();
            $this->audit->record(
                $old === null ? 'education.structure_created' : 'education.structure_updated',
                $record,
                old: $old ?? [],
                new: ['kind' => $kind, ...$this->values($record, $definition)],
                actor: $actor,
                organizationId: $company->getKey(),
            );

            return $record;
        });
    }

    /** Take out a curriculum entry or a prerequisite (they have no history of their own). */
    public function remove(Organization $company, string $kind, Model $record, User $actor): void
    {
        $definition = self::definition($kind);
        if (! ($definition['removable'] ?? false)) {
            throw EducationException::inUse('record');
        }

        $this->education->transaction($company, function () use ($company, $kind, $definition, $record, $actor) {
            $this->audit->record('education.structure_removed', $record, old: ['kind' => $kind, ...$this->values($record, $definition)], actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /**
     * @param  array{model: class-string<Model>, fields: list<string>, texts: list<string>, refs: array<string, class-string<Model>>, unique: list<string>}  $definition
     */
    private function checkReferences(Organization $company, array $definition, Model $record): void
    {
        foreach ($definition['refs'] as $field => $model) {
            $id = $record->{$field};
            if ($id === null || $id === '') {
                continue;
            }
            $found = $this->education->query($model, $company)->whereKey($id)->first();
            if ($found === null || ($field === 'parent_id' || $field === 'next_level_id') && $id === $record->getKey()) {
                throw ValidationException::withMessages([$field => __('education::education.validation.reference')]);
            }
            if (isset(self::LIST_KINDS[$field]) && $found->kind !== self::LIST_KINDS[$field]) {
                throw ValidationException::withMessages([$field => __('education::education.validation.reference')]);
            }
        }
    }

    /**
     * @param  array{model: class-string<Model>, unique: list<string>}  $definition
     */
    private function checkUnique(Organization $company, string $kind, array $definition, Model $record): void
    {
        $scopes = match ($kind) {
            'levels' => [['program_id', 'code']],
            'lists' => [['kind', 'key']],
            'sessions' => [['academic_year_id', 'kind', 'sequence']],
            'sections' => [['session_id', 'level_id', 'unit_id', 'name']],
            'batches', 'curricula' => [['program_id', 'name']],
            'curriculum_items' => [['curriculum_id', 'level_id', 'subject_id', 'stream_id']],
            'prerequisites' => [['subject_id', 'requires_subject_id']],
            default => array_map(fn (string $field) => [$field], $definition['unique']),
        };

        foreach ($scopes as $columns) {
            // Nothing to compare (an optional code left empty).
            if (collect($columns)->every(fn (string $column) => $record->{$column} === null)) {
                continue;
            }
            $query = $this->education->query($definition['model'], $company)->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()));
            foreach ($columns as $column) {
                $record->{$column} === null ? $query->whereNull($column) : $query->where($column, $record->{$column});
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([end($columns) => __('education::education.validation.taken')]);
            }
        }
    }

    /** What must fit, kind by kind. */
    private function checkKind(Organization $company, string $kind, Model $record): void
    {
        $find = fn (string $model, ?string $id) => $id === null ? null : $this->education->query($model, $company)->whereKey($id)->first();

        match ($kind) {
            'programs' => $record->progression === 'year' && $record->periods_per_year !== 1
                ? throw ValidationException::withMessages(['periods_per_year' => __('education::education.validation.year_one_period')]) : null,
            'levels' => $record->next_level_id !== null && $find(Level::class, $record->next_level_id)?->program_id !== $record->program_id
                ? throw EducationException::mismatch('next_level_id') : null,
            'years', 'sessions' => $this->checkDates($company, $kind, $record),
            'sections' => $this->checkSection($company, $record),
            'batches' => $record->curriculum_id !== null && $find(Curriculum::class, $record->curriculum_id)?->program_id !== $record->program_id
                ? throw EducationException::mismatch('curriculum_id') : null,
            'curriculum_items' => $find(Level::class, $record->level_id)?->program_id !== $find(Curriculum::class, $record->curriculum_id)?->program_id
                ? throw EducationException::mismatch('level_id') : null,
            'prerequisites' => $this->checkPrerequisite($company, $record),
            'lists' => $record->exists && $record->isDirty('kind') ? throw EducationException::inUse('list') : null,
            default => null,
        };

        // Switching a level or a section off while students are in it would hide them.
        if (in_array($kind, ['levels', 'sections'], true) && $record->exists && $record->isDirty('is_active') && ! $record->is_active) {
            $column = $kind === 'levels' ? 'level_id' : 'section_id';
            if ($this->education->query(Enrollment::class, $company)->where($column, $record->getKey())->where('status', 'active')->exists()) {
                throw EducationException::inUse('record');
            }
        }
    }

    private function checkDates(Organization $company, string $kind, Model $record): void
    {
        if ($record->starts_on > $record->ends_on) {
            throw ValidationException::withMessages(['ends_on' => __('education::education.validation.dates')]);
        }
        if ($kind === 'sessions') {
            $year = $this->education->query(AcademicYear::class, $company)->whereKey($record->academic_year_id)->first();
            if ($year !== null && ($record->starts_on < $year->starts_on || $record->ends_on > $year->ends_on)) {
                throw ValidationException::withMessages(['starts_on' => __('education::education.validation.inside_year')]);
            }
        }
    }

    private function checkSection(Organization $company, Section $section): void
    {
        // The campus is this institution or one of its units (never a group or another company).
        if (! in_array($section->unit_id, $this->education->subtreeIds($company), true)) {
            throw ValidationException::withMessages(['unit_id' => __('education::education.validation.reference')]);
        }
        $session = $this->education->query(Session::class, $company)->whereKey($section->session_id)->first();
        $level = $this->education->query(Level::class, $company)->whereKey($section->level_id)->first();
        $program = $level === null ? null : $this->education->query(Program::class, $company)->whereKey($level->program_id)->first();
        if ($session !== null && $program !== null && $session->kind !== $program->progression) {
            throw EducationException::mismatch('session_id');
        }
        if ($section->exists && $section->isDirty('capacity')) {
            $taken = $this->education->query(Enrollment::class, $company)->where('section_id', $section->getKey())->where('status', 'active')->count();
            if ($section->capacity < $taken) {
                throw ValidationException::withMessages(['capacity' => __('education::education.validation.capacity_below', ['count' => $taken])]);
            }
        }
        if ($section->class_teacher_id !== null && $section->isDirty('class_teacher_id') && ! app(Teachers::class)->exists($company, $section->class_teacher_id)) {
            throw ValidationException::withMessages(['class_teacher_id' => __('education::education.validation.reference')]);
        }
    }

    private function checkPrerequisite(Organization $company, Prerequisite $prerequisite): void
    {
        if ($prerequisite->subject_id === $prerequisite->requires_subject_id) {
            throw EducationException::mismatch('requires_subject_id');
        }
        // A subject cannot need, through a chain, a subject that needs it.
        $pairs = $this->education->query(Prerequisite::class, $company)->get(['subject_id', 'requires_subject_id'])
            ->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('requires_subject_id')->all());
        $seen = [];
        $stack = [$prerequisite->requires_subject_id];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $prerequisite->subject_id) {
                throw EducationException::mismatch('requires_subject_id');
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($stack, ...($pairs[$current] ?? []));
        }
    }

    private function ordered(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'programs' => $query->orderBy('sort_order')->orderBy('code'),
            'lists' => $query->orderBy('kind')->orderBy('sort_order')->orderBy('key'),
            'levels' => $query->orderBy('program_id')->orderBy('sequence'),
            'years' => $query->orderByDesc('starts_on'),
            'sessions' => $query->orderByDesc('starts_on')->orderBy('sequence'),
            'sections' => $query->orderBy('level_id')->orderBy('name'),
            'subjects', 'units' => $query->orderBy('code'),
            'curriculum_items' => $query->orderBy('level_id')->orderBy('sort_order'),
            default => $query->orderBy('created_at'),
        };
    }

    /**
     * @param  array{fields: list<string>, texts: list<string>}  $definition
     * @return array<string, mixed>
     */
    private function values(Model $record, array $definition): array
    {
        $values = $record->only($definition['fields']);
        foreach ($definition['texts'] as $field) {
            $values[$field] = $record->texts($field);
        }

        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }
}
