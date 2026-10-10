<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Models\Field;
use Modules\Education\Services\Fields;
use Modules\Education\Services\Presets;
use Modules\Education\Services\Structure;
use Modules\Education\Services\Teachers;

/**
 * What the education screens start with (education.view): the structure
 * people pick from, the campuses here, teachers (with HRM on), the presets
 * and what the reader may do. Applying a preset needs education.manage.
 */
class SetupController extends Controller
{
    use FindsEducation;

    public function __construct(private Structure $structure, private Presets $presets, private Teachers $teachers, private Fields $fields, private EducationPresenter $presenter) {}

    public function show(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.view', $unit);
        $units = $this->unitIds($unit);
        $present = fn (string $kind, array $filters = []) => $this->structure->list($company, $kind, $filters)->map(fn ($record) => $this->presenter->structure($kind, $record))->values();

        return response()->json(['data' => [
            'institution' => ['id' => $company->getKey(), 'name' => $company->displayName()],
            'campuses' => Organization::query()->whereKey($units)->whereNot('type', OrganizationType::Department->value)->orderBy('depth')->get()
                ->map(fn (Organization $campus) => ['id' => $campus->getKey(), 'name' => $campus->displayName(), 'type' => $campus->type->value])->values(),
            'programs' => $present('programs'),
            'levels' => $present('levels'),
            'lists' => $present('lists'),
            'years' => $present('years'),
            'sessions' => $present('sessions'),
            'units' => $present('units'),
            'batches' => $present('batches'),
            // The own fields forms ask for, by record kind (sensitive ones only for people who may see them).
            'fields' => collect(Field::ENTITIES)->mapWithKeys(fn (string $entity) => [$entity => $this->fields->of($company, $entity)
                ->filter(fn (Field $field) => ! $field->is_sensitive || Gate::allows('education.view_sensitive', $unit))
                ->map(fn (Field $field) => $this->presenter->field($field))->values()]),
            'teachers' => Gate::allows('education.manage', $unit) ? $this->teachers->in($company, $units) : [],
            'hrm' => $this->teachers->available($company),
            'presets' => $this->presets->all(),
            'can' => [
                'manage' => Gate::allows('education.manage', $unit),
                'admit' => Gate::allows('education.admit', $unit),
                'edit_students' => Gate::allows('education.edit_students', $unit),
                'view_sensitive' => Gate::allows('education.view_sensitive', $unit),
            ],
        ]]);
    }

    public function applyPreset(Request $request, string $organization, string $preset): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.manage', $unit);
        $made = $this->presets->apply($company, $preset, $request->user());

        return response()->json(['data' => ['made' => $made], 'message' => __('education::education.messages.preset_applied', ['count' => array_sum($made)])]);
    }
}
