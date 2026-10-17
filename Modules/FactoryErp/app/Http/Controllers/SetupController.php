<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\FieldRequest;
use Modules\FactoryErp\Models\Field;
use Modules\FactoryErp\Services\Fields;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Styles;
use Modules\Inventory\Services\Stock;

/**
 * What the factory screens need once (what the person may do, the rules,
 * Inventory items and units for the bill of materials, own fields), the
 * buyers (CRM organization contacts tagged "buyer": found and added here),
 * and the company's own fields on styles and orders (set up with
 * factory_erp.manage at the company).
 */
class SetupController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private Fields $fields,
        private Organizations $buyers,
        private Stock $stock,
        private FactoryPresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function setup(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $context = $this->contexts->forOrganization($unit);

        return response()->json(['data' => [
            'can' => [
                'merchandise' => Gate::allows('factory_erp.merchandise', $unit),
                'issue' => Gate::allows('factory_erp.issue', $unit),
                'produce' => Gate::allows('factory_erp.produce', $unit),
                'qc' => Gate::allows('factory_erp.qc', $unit),
                'manage' => Gate::allows('factory_erp.manage', $company),
                'crm' => Gate::allows('crm.view', $company),
            ],
            'rules' => [
                'consumption_basis' => (string) $this->rules->get('factory_erp.consumption_basis', $context),
                'default_wastage_bp' => (int) $this->rules->get('factory_erp.default_wastage_bp', $context),
                'order_currency' => (string) $this->rules->get('factory_erp.order_currency', $context),
                'over_issue' => (string) $this->rules->get('factory_erp.over_issue', $context),
                'over_cut_bp' => (int) $this->rules->get('factory_erp.over_cut_bp', $context),
                'output_by' => (string) $this->rules->get('factory_erp.output_by', $context),
                'reject_warn_bp' => (int) $this->rules->get('factory_erp.reject_warn_bp', $context),
                'shift_minutes' => (int) $this->rules->get('factory_erp.shift_minutes', $context),
                'target_efficiency_bp' => (int) $this->rules->get('factory_erp.target_efficiency_bp', $context),
                'efficiency_counts_helpers' => (bool) $this->rules->get('factory_erp.efficiency_counts_helpers', $context),
            ],
            // The company's own (costs of materials); none set yet is not an error here.
            'currency' => rescue(fn () => $this->floor->currency($company), null, false),
            'warehouses' => $this->stock->warehouses($company),
            'today' => $this->floor->today($company)->toDateString(),
            'items' => array_map(fn (array $item) => ['id' => $item['id'], 'sku' => $item['sku'], 'name' => $item['name'], 'unit_id' => $item['unit_id']], $this->stock->items($company)),
            'units' => $this->stock->units($company),
            'fields' => collect(Field::ENTITIES)->mapWithKeys(fn (string $entity) => [$entity => $this->fields->of($company, $entity)->map(fn (Field $field) => $this->presenter->field($field))->values()]),
        ]]);
    }

    /** Buyers to choose from (CRM). */
    public function buyers(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $term = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return response()->json(['data' => $this->buyers->search($company, Styles::BUYER_TAG, $term)]);
    }

    /** A new buyer, kept in CRM as an organization contact tagged "buyer". */
    public function addBuyer(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.merchandise', $unit);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'], 'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500'], 'op_id' => ['nullable', 'string', 'max:64'],
        ]);
        if (! $this->buyers->available($company)) {
            throw FactoryException::notABuyer();
        }

        return response()->json(['data' => $this->buyers->add($company, $unit->getKey(), Styles::BUYER_TAG, $data, $request->user())], 201);
    }

    public function fields(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return response()->json(['data' => collect(Field::ENTITIES)->flatMap(fn (string $entity) => $this->fields->of($company, $entity, false))->map(fn (Field $field) => $this->presenter->field($field))->values()]);
    }

    public function storeField(FieldRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.manage', $company);

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, null, null, $request->validated(), $request->user()))], 201);
    }

    public function updateField(FieldRequest $request, string $organization, string $field): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.manage', $company);
        $found = $this->floor->query(Field::class, $company)->whereKey($field)->first() ?? throw FactoryException::notFound('field');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->field($this->fields->save($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }
}
