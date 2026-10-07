<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Http\Requests\FieldRequest;
use Modules\Crm\Http\Requests\PipelineRequest;
use Modules\Crm\Models\Field;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Stage;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Fields;
use Modules\Crm\Services\Pipelines;

/**
 * The company's own CRM set-up (crm.manage at the company): pipelines and
 * their stages, and the extra fields of contacts, deals, estimates and
 * quotations and their lines. Read with the setup (SetupController).
 */
class SettingsController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Pipelines $pipelines, private Fields $fields, private CrmPresenter $presenter) {}

    public function storePipeline(PipelineRequest $request, string $organization): JsonResponse
    {
        $company = $this->companyHere($organization);

        return response()->json(['data' => $this->presenter->pipeline($this->pipelines->create($company, $request->validated(), $request->user()))], 201);
    }

    public function updatePipeline(PipelineRequest $request, string $organization, string $pipeline): JsonResponse
    {
        $company = $this->companyHere($organization);
        $found = $this->crm->query(Pipeline::class, $company)->whereKey($pipeline)->first() ?? throw CrmException::notFound('pipeline');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->pipeline($this->pipelines->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function storeStage(PipelineRequest $request, string $organization, string $pipeline): JsonResponse
    {
        $company = $this->companyHere($organization);
        $found = $this->crm->query(Pipeline::class, $company)->whereKey($pipeline)->first() ?? throw CrmException::notFound('pipeline');

        return response()->json(['data' => $this->presenter->stage($this->pipelines->saveStage($company, $found, null, null, $request->validated(), $request->user()))], 201);
    }

    public function updateStage(PipelineRequest $request, string $organization, string $pipeline, string $stage): JsonResponse
    {
        $company = $this->companyHere($organization);
        $found = $this->crm->query(Pipeline::class, $company)->whereKey($pipeline)->first() ?? throw CrmException::notFound('pipeline');
        $row = $this->crm->query(Stage::class, $company)->whereKey($stage)->where('pipeline_id', $found->getKey())->first() ?? throw CrmException::notFound('stage');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->stage($this->pipelines->saveStage($company, $found, $row, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function fields(string $organization): JsonResponse
    {
        $company = $this->companyHere($organization, 'crm.view');

        return response()->json(['data' => collect(Field::ENTITIES)->flatMap(fn (string $entity) => $this->fields->of($company, $entity, false))->map(fn (Field $field) => $this->presenter->field($field))->values()]);
    }

    public function storeField(FieldRequest $request, string $organization): JsonResponse
    {
        $company = $this->companyHere($organization);

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, null, null, $request->validated(), $request->user()))], 201);
    }

    public function updateField(FieldRequest $request, string $organization, string $field): JsonResponse
    {
        $company = $this->companyHere($organization);
        $found = $this->crm->query(Field::class, $company)->whereKey($field)->first() ?? throw CrmException::notFound('field');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    /** Set-up is the company's: the address must be the company (or a unit of it, checked at the company). */
    private function companyHere(string $organization, string $permission = 'crm.manage')
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize($permission, $company);

        return $company;
    }
}
