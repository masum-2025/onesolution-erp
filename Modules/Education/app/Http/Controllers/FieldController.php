<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Http\Requests\FieldRequest;
use Modules\Education\Models\Field;
use Modules\Education\Services\Education;
use Modules\Education\Services\Fields;

/**
 * The institution's own fields: read with education.view (forms need
 * them), made and changed with education.manage.
 */
class FieldController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Fields $fields, private EducationPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.view', $unit);
        $filters = $request->validate(['entity' => ['nullable', 'in:'.implode(',', Field::ENTITIES)], 'all' => ['nullable', 'boolean']]);
        $fields = $this->education->query(Field::class, $company)
            ->when($filters['entity'] ?? null, fn ($query, $entity) => $query->where('entity', $entity))
            ->when(! ($filters['all'] ?? false), fn ($query) => $query->where('is_active', true))
            ->orderBy('entity')->orderBy('sort_order')->orderBy('key')->get();

        return response()->json(['data' => $fields->map(fn (Field $field) => $this->presenter->field($field))->values()]);
    }

    public function store(FieldRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.manage', $unit);

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, null, null, $request->validated(), $request->user()))], 201);
    }

    public function update(FieldRequest $request, string $organization, string $field): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->education->find(Field::class, $company, $field, 'field');
        Gate::authorize('education.manage', $unit);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }
}
