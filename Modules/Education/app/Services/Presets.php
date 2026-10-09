<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\AcademicUnit;
use Modules\Education\Models\Field;
use Modules\Education\Models\Level;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Program;
use Modules\Education\Models\Subject;

/**
 * Ready-made structures (database/data/presets/{key}.php): lists, faculties,
 * programs with their levels, subjects and own fields for a kind of
 * institution in a country. Applying makes only what is missing (matched by
 * key or code), so it is safe to apply again, or to apply a second preset
 * (a school with a college section); everything stays editable afterwards.
 * A new country or kind of institution is a new data file.
 */
class Presets
{
    public function __construct(private Education $education, private AuditLogger $audit) {}

    /**
     * @return list<array{key: string, name: array<string, string>, description: array<string, string>, sectors: list<string>}>
     */
    public function all(): array
    {
        $presets = [];
        foreach (glob(dirname(__DIR__, 2).'/database/data/presets/*.php') ?: [] as $file) {
            // Shared parts ("_common_lists.php") are not presets.
            if (str_starts_with(basename($file), '_')) {
                continue;
            }
            $preset = require $file;
            $presets[] = array_intersect_key($preset, array_flip(['key', 'name', 'description', 'sectors']));
        }
        usort($presets, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $presets;
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $file = dirname(__DIR__, 2)."/database/data/presets/{$key}.php";
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || ! is_file($file)) {
            throw EducationException::unknownPreset();
        }

        return require $file;
    }

    /**
     * @return array<string, int> What was made, by kind.
     */
    public function apply(Organization $company, string $key, User $actor): array
    {
        $preset = $this->get($key);

        return $this->education->transaction($company, function () use ($company, $key, $preset, $actor) {
            $made = ['lists' => 0, 'units' => 0, 'programs' => 0, 'levels' => 0, 'subjects' => 0, 'fields' => 0];

            foreach ($preset['lists'] ?? [] as $kind => $items) {
                foreach ($items as $order => $item) {
                    $made['lists'] += $this->firstOrMake(ListItem::class, $company, ['kind' => $kind, 'key' => $item['key']], ['name' => $item['name'], 'sort_order' => $order]);
                }
            }

            $units = [];
            foreach ($preset['units'] ?? [] as $unit) {
                $parent = isset($unit['parent']) ? ($units[$unit['parent']] ?? null) : null;
                $made['units'] += $this->firstOrMake(AcademicUnit::class, $company, ['code' => $unit['code']], ['kind' => $unit['kind'], 'name' => $unit['name'], 'parent_id' => $parent]);
                $units[$unit['code']] = $this->education->query(AcademicUnit::class, $company)->where('code', $unit['code'])->value('id');
            }

            foreach ($preset['programs'] ?? [] as $order => $program) {
                $made['programs'] += $this->firstOrMake(Program::class, $company, ['code' => $program['code']], [
                    'name' => $program['name'],
                    'progression' => $program['progression'],
                    'periods_per_year' => $program['periods_per_year'] ?? 1,
                    'total_credits_centi' => $program['total_credits_centi'] ?? null,
                    'level_label' => $program['level_label'] ?? null,
                    'section_label' => $program['section_label'] ?? null,
                    'academic_unit_id' => isset($program['unit']) ? ($units[$program['unit']] ?? null) : null,
                    'sort_order' => $order,
                ]);
                $programId = $this->education->query(Program::class, $company)->where('code', $program['code'])->value('id');

                // Levels in order; each promotes to the next one, the last to none.
                $previous = null;
                foreach ($program['levels'] as $sequence => $level) {
                    $made['levels'] += $this->firstOrMake(Level::class, $company, ['program_id' => $programId, 'code' => $level['code']], [
                        'name' => $level['name'],
                        'sequence' => $sequence + 1,
                        'min_age' => $level['min_age'] ?? null,
                    ]);
                    $current = $this->education->query(Level::class, $company)->where('program_id', $programId)->where('code', $level['code'])->first();
                    if ($previous !== null && $previous->next_level_id === null) {
                        $previous->forceFill(['next_level_id' => $current->getKey()])->save();
                    }
                    $previous = $current;
                }
            }

            foreach ($preset['subjects'] ?? [] as $subject) {
                $made['subjects'] += $this->firstOrMake(Subject::class, $company, ['code' => $subject['code']], [
                    'name' => $subject['name'],
                    'credits_centi' => $subject['credits_centi'] ?? null,
                    'kind' => $subject['kind'] ?? 'theory',
                ]);
            }

            foreach ($preset['fields'] ?? [] as $order => $field) {
                $made['fields'] += $this->firstOrMake(Field::class, $company, ['entity' => $field['entity'], 'key' => $field['key']], [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'options' => $field['options'] ?? null,
                    'is_required' => $field['is_required'] ?? false,
                    'portal_visible' => $field['portal_visible'] ?? false,
                    'on_documents' => $field['on_documents'] ?? false,
                    'is_sensitive' => $field['is_sensitive'] ?? false,
                    'sort_order' => $order,
                ]);
            }

            $this->audit->record('education.preset_applied', null, new: ['preset' => $key, 'made' => $made], actor: $actor, organizationId: $company->getKey());

            return $made;
        });
    }

    /**
     * Make a record unless one with these keys is there. 1 when made.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function firstOrMake(string $model, Organization $company, array $keys, array $values): int
    {
        $query = $this->education->query($model, $company);
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }
        if ($query->exists()) {
            return 0;
        }

        $record = new $model;
        $texts = $record->translatable ?? [];
        $record->fill(['organization_id' => $company->getKey(), ...$keys, ...array_diff_key($values, array_flip($texts))]);
        foreach ($texts as $field) {
            if (array_key_exists($field, $values)) {
                $record->putTexts($field, $values[$field]);
            }
        }
        if (in_array('version', $record->getFillable(), true)) {
            $record->version = 1;
        }
        $record->save();

        return 1;
    }
}
