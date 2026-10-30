<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Projects\Http\ProjectsPresenter;
use Modules\Projects\Http\Requests\TemplateRequest;
use Modules\Projects\Services\Templates;

/**
 * Templates of the usual steps usable at the unit in the address (its own
 * and those kept above). A group or a company keeps them (projects.configure).
 */
class TemplateController extends Controller
{
    use FindsWorkplace;

    public function __construct(private Templates $templates, private ProjectsPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unitWithAny($organization, ['projects.view', 'projects.configure']);

        return response()->json(['data' => $this->templates->usableAt($unit, $request->boolean('all'))
            ->map(fn ($template) => $this->presenter->template($template, $unit))->values()]);
    }

    public function show(string $organization, string $template): JsonResponse
    {
        $unit = $this->unitWithAny($organization, ['projects.view', 'projects.configure']);
        $found = $this->templates->find($unit, $template);

        return response()->json(['data' => $this->presenter->template($found, $unit, $this->templates->steps($found))]);
    }

    public function store(TemplateRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.configure');
        $made = $this->templates->save($unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->template($made, $unit, $this->templates->steps($made))], 201);
    }

    public function update(TemplateRequest $request, string $organization, string $template): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.configure');
        $saved = $this->templates->save($unit, $this->templates->find($unit, $template), $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->template($saved, $unit, $this->templates->steps($saved))]);
    }
}
