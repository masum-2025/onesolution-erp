<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Enums\CustomFieldType;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\CustomFieldRequest;
use Modules\Hrm\Models\CustomField;
use Modules\Hrm\Services\CustomFields;
use Modules\Hrm\Services\Units;

/**
 * Extra employee fields of a unit: its own and those set up above it in the
 * company (shown as inherited, changed only where they were set up). Reading
 * needs hrm.view; adding and changing hrm.configure. The number in use per
 * company is the rule hrm.custom_fields_max. Never removed, switched off.
 */
class CustomFieldController extends Controller
{
    use FindsHrmRecords;

    public function __construct(
        private CustomFields $fields,
        private Units $units,
        private EmployeePresenter $presenter,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $request->validate(['unit_id' => ['nullable', 'string', 'size:26'], 'all' => ['nullable', 'boolean']]);
        $unit = $this->unitIn($this->findVisible($organization), $request->input('unit_id'));
        Gate::authorize('hrm.view', $unit);

        return response()->json([
            'data' => $this->fields->applicableTo($unit, activeOnly: ! $request->boolean('all'))
                ->map(fn (CustomField $field) => $this->presenter->customField($field, $unit))->values(),
            'meta' => [
                'max' => $this->max($unit),
                'in_use' => $this->inUse($this->units->companyOf($unit)),
                'can_configure' => Gate::allows('hrm.configure', $unit),
            ],
        ]);
    }

    public function store(CustomFieldRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unitIn($this->findVisible($organization), $request->validated('organization_id'));
        Gate::authorize('hrm.configure', $unit);
        $company = $this->units->companyOf($unit);
        $data = $request->validated();
        $type = CustomFieldType::from($data['type']);

        if ($this->inUse($company) >= ($max = $this->max($unit))) {
            throw HrmException::tooManyFields($max);
        }
        if ($this->companyFields($company)->where('key', $data['key'])->exists()) {
            throw ValidationException::withMessages(['key' => __('hrm::hrm.validation.custom_key_taken')]);
        }
        $options = $this->options($type, $data['options'] ?? null, []);

        $field = new CustomField;
        $field->fill([
            'organization_id' => $unit->getKey(),
            'company_id' => $company->getKey(),
            'key' => $data['key'],
            'type' => $type,
            'options' => $options,
            'is_required' => $data['is_required'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
            'version' => 1,
        ]);
        $field->putTexts('label', $data['label'])->save();

        $this->audit->record('hrm.custom_field_created', $field, new: $this->auditShape($field), actor: $request->user(), organizationId: $unit->getKey());

        return response()->json(['data' => $this->presenter->customField($field, $unit)], 201);
    }

    public function update(CustomFieldRequest $request, string $organization, string $field): JsonResponse
    {
        $organization = $this->findVisible($organization);
        $company = $this->units->companyOf($organization);
        $found = $this->companyFields($company)->whereKey($field)->first();

        // Visible from here: set up in this unit, above it in the company, or below it.
        if ($found === null || ! ($this->inside($organization, $found->organization_id) || in_array($found->organization_id, $organization->ancestorIds(), true))) {
            throw HrmException::customFieldNotFound();
        }
        $home = Organization::query()->findOrFail($found->organization_id);
        if (! $this->inside($organization, $home->getKey())) {
            throw HrmException::customFieldElsewhere($home->displayName());
        }
        Gate::authorize('hrm.configure', $home);

        $data = $request->validated();
        if ($found->version !== (int) $data['base_version']) {
            throw HrmException::versionConflict($this->presenter->customField($found, $organization));
        }
        if (($data['is_active'] ?? false) && ! $found->is_active && $this->inUse($company) >= ($max = $this->max($home))) {
            throw HrmException::tooManyFields($max);
        }

        $old = $this->auditShape($found);
        if (array_key_exists('options', $data)) {
            $found->options = $this->options($found->type, $data['options'], $found->optionValues());
        }
        $found->fill(array_intersect_key($data, array_flip(['is_required', 'sort_order', 'is_active'])));
        if (isset($data['label'])) {
            $found->putTexts('label', $data['label']);
        }
        $found->version = $found->version + 1;
        $found->save();

        $this->audit->record('hrm.custom_field_updated', $found, old: $old, new: $this->auditShape($found), actor: $request->user(), organizationId: $found->organization_id);

        return response()->json(['data' => $this->presenter->customField($found, $organization)]);
    }

    /**
     * Options of a choice field (others have none). Values already offered stay.
     *
     * @param  list<array{value: string, label: array<string, string>}>|null  $given
     * @param  list<string>  $kept
     * @return list<array{value: string, label: array<string, string>}>|null
     */
    private function options(CustomFieldType $type, ?array $given, array $kept): ?array
    {
        if ($type !== CustomFieldType::Choice) {
            return null;
        }
        if ($given === null || $given === []) {
            throw ValidationException::withMessages(['options' => __('hrm::hrm.validation.custom_options_required')]);
        }
        if (array_diff($kept, array_column($given, 'value')) !== []) {
            throw ValidationException::withMessages(['options' => __('hrm::hrm.validation.custom_options_kept')]);
        }

        return array_values(array_map(fn (array $option) => [
            'value' => $option['value'],
            'label' => array_filter($option['label'], fn ($text) => $text !== null && $text !== ''),
        ], $given));
    }

    private function companyFields(Organization $company)
    {
        return CustomField::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('company_id', $company->getKey());
    }

    private function inUse(Organization $company): int
    {
        return $this->companyFields($company)->where('is_active', true)->count();
    }

    private function max(Organization $unit): int
    {
        return (int) $this->rules->get('hrm.custom_fields_max', $this->contexts->forOrganization($unit));
    }

    /** @return array<string, mixed> */
    private function auditShape(CustomField $field): array
    {
        return [
            'key' => $field->key,
            'type' => $field->type->value,
            'label' => $field->texts('label'),
            'options' => $field->optionValues(),
            'is_required' => $field->is_required,
            'is_active' => $field->is_active,
        ];
    }
}
