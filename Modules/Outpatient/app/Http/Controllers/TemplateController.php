<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\Requests\ConsultationRequest;
use Modules\Outpatient\Models\Template;
use Modules\Outpatient\Services\Templates;

/** Prescription templates (outpatient.consult): the company's shared ones and the person's own. */
class TemplateController extends Controller
{
    use FindsOutpatient;

    public function __construct(private Templates $templates) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);

        return response()->json([
            'data' => $this->templates->visible($company, $request->user())->map(fn (Template $template) => $this->row($template))->values(),
            'meta' => ['can' => ['share' => Gate::allows('outpatient.manage', $unit)]],
        ]);
    }

    public function store(ConsultationRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);

        return response()->json(['data' => $this->row($this->templates->save($company, $unit, null, $request->validated(), null, $request->user()))], 201);
    }

    public function update(ConsultationRequest $request, string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        $found = $this->templates->find($company, $request->user(), $template);
        $data = $request->validated();

        return response()->json(['data' => $this->row($this->templates->save($company, $unit, $found, $data, (int) $data['base_version'], $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $template): Response
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        $found = $this->templates->find($company, $request->user(), $template);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->templates->delete($company, $unit, $found, $version, $request->user());

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function row(Template $template): array
    {
        return ['id' => $template->getKey(), 'name' => $template->name, 'shared' => $template->doctor_id === null, 'body' => $template->body, 'version' => $template->version];
    }
}
