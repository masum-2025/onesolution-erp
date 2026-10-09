<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Http\Requests\StructureRequest;
use Modules\Education\Services\Education;
use Modules\Education\Services\Structure;

/**
 * The institution's structure, kind by kind (units, programs, levels,
 * lists, years, sessions, sections, batches, subjects, curricula and their
 * entries, prerequisites): read with education.view, changed with
 * education.manage. Sections are listed for the campus in the address and
 * below; one is made only at such a campus.
 */
class StructureController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Structure $structure, private EducationPresenter $presenter) {}

    public function index(Request $request, string $organization, string $kind): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Structure::definition($kind);
        Gate::authorize('education.view', $unit);
        $filters = $request->validate(array_fill_keys(['kind', 'program_id', 'academic_year_id', 'session_id', 'level_id', 'unit_id', 'curriculum_id', 'subject_id', 'status', 'is_active', 'parent_id'], ['nullable', 'string', 'max:40']));

        return response()->json(['data' => $this->structure->list($company, $kind, $filters, $this->unitIds($unit))->map(fn ($record) => $this->presenter->structure($kind, $record))->values()]);
    }

    public function store(StructureRequest $request, string $organization, string $kind): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Structure::definition($kind);
        Gate::authorize('education.manage', $unit);
        $data = $request->validated();
        if ($kind === 'sections') {
            $data['unit_id'] = $this->unitFor($unit, $data['unit_id']);
        }
        $record = $this->structure->save($company, $kind, null, null, $data, $request->user());

        return response()->json(['data' => $this->presenter->structure($kind, $record)], 201);
    }

    public function update(StructureRequest $request, string $organization, string $kind, string $id): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $record = $this->record($unit, $company, $kind, $id);
        Gate::authorize('education.manage', $unit);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->structure($kind, $this->structure->save($company, $kind, $record, isset($data['base_version']) ? (int) $data['base_version'] : null, $data, $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $kind, string $id): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $record = $this->record($unit, $company, $kind, $id);
        Gate::authorize('education.manage', $unit);
        $this->structure->remove($company, $kind, $record, $request->user());

        return response()->json(['data' => null, 'message' => __('education::education.messages.removed')]);
    }

    private function record($unit, $company, string $kind, string $id)
    {
        $definition = Structure::definition($kind);
        $record = $this->education->query($definition['model'], $company)->whereKey($id)->first();
        // A section of another campus looks unknown.
        if ($record === null || ($kind === 'sections' && ! in_array($record->unit_id, $this->unitIds($unit), true))) {
            throw EducationException::notFound('record');
        }

        return $record;
    }
}
