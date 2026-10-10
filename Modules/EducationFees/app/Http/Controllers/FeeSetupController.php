<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Accounting\Services\TaxCodes;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Http\Controllers\Concerns\FindsFeeUnit;
use Modules\EducationFees\Http\FeePresenter;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeStructure;
use Modules\EducationFees\Services\FeeOffice;
use Modules\EducationFees\Services\FeeSetup;

/**
 * Fee heads and fee structures of the institution (read with
 * education_fees.view, changed with .configure), and what the fee screens
 * start with.
 */
class FeeSetupController extends Controller
{
    use FindsFeeUnit;

    private const CAN = ['configure', 'bill', 'concede', 'approve', 'collect', 'void'];

    public function __construct(private FeeOffice $office, private FeeSetup $setup, private FeePresenter $presenter) {}

    public function setup(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $academic = app(AcademicDirectory::class);
        $rules = app(RuleResolver::class);
        $context = app(RuleContextFactory::class)->forOrganization($unit);
        $books = app(ModuleResolver::class)->isEnabled('accounting', $company);

        return response()->json(['data' => [
            'heads' => $this->setup->heads($company)->map(fn (FeeHead $head) => $this->presenter->head($head))->values(),
            'income_keys' => array_map(fn (string $key) => ['key' => $key, 'label' => __('education_fees::fees.posting_keys.'.substr($key, strlen('education_fees.')))], $this->setup->incomeKeys()),
            'tax_codes' => $books ? app(TaxCodes::class)->salesCodes($company) : [],
            'sessions' => $academic->sessions($company),
            'levels' => $academic->levels($company),
            'campuses' => Organization::query()->whereKey($this->unitIds($unit))->whereNot('type', OrganizationType::Department->value)->orderBy('depth')->get()
                ->map(fn (Organization $campus) => ['id' => $campus->getKey(), 'name' => $campus->displayName()])->values(),
            'rules' => [
                'due_day' => (int) $rules->get('education_fees.due_day', $context),
                'sibling_discount_percent' => (int) $rules->get('education_fees.sibling_discount_percent', $context),
                'concession_approval_above_percent' => $rules->get('education_fees.concession_approval_above_percent', $context),
                'late_fine' => $rules->get('education_fees.late_fine', $context),
                'auto_monthly_billing' => $rules->get('education_fees.auto_monthly_billing', $context),
                'bill_on_admission' => (bool) $rules->get('education_fees.bill_on_admission', $context),
                'fees_include_tax' => (bool) $rules->get('education_fees.fees_include_tax', $context),
            ],
            'today' => $this->office->today($company)->toDateString(),
            'can' => array_combine(self::CAN, array_map(fn (string $can) => Gate::allows("education_fees.{$can}", $unit), self::CAN)),
        ]]);
    }

    public function storeHead(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $unit);
        $data = $request->validate($this->headRules($company, null));
        $head = $this->setup->saveHead($company, null, $data, $request->user());

        return response()->json(['data' => $this->presenter->head($head)], 201);
    }

    public function updateHead(Request $request, string $organization, string $head): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $unit);
        $found = $this->office->find(FeeHead::class, $company, $head, 'head');
        $data = $request->validate([...$this->headRules($company, $found), 'base_version' => ['required', 'integer']]);
        $saved = $this->setup->saveHead($company, $found, $data, $request->user());

        return response()->json(['data' => $this->presenter->head($saved)]);
    }

    public function structures(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate(['session_id' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', Rule::in(FeeStructure::STATUSES)]]);
        $structures = $this->office->query(FeeStructure::class, $company)
            ->when($filters['session_id'] ?? null, fn ($query, $id) => $query->where('session_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $structures->map(fn (FeeStructure $structure) => $this->presenter->structure($structure, $this->setup->lines($company, $structure)))->values()]);
    }

    public function showStructure(string $organization, string $structure): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $found = $this->office->find(FeeStructure::class, $company, $structure, 'structure');

        return response()->json(['data' => $this->presenter->structure($found, $this->setup->lines($company, $found))]);
    }

    public function storeStructure(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $unit);
        $data = $request->validate($this->structureRules($company, $unit, true));
        $structure = $this->setup->saveStructure($company, null, $data, $request->user());

        return response()->json(['data' => $this->presenter->structure($structure, $this->setup->lines($company, $structure))], 201);
    }

    public function updateStructure(Request $request, string $organization, string $structure): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $unit);
        $found = $this->office->find(FeeStructure::class, $company, $structure, 'structure');
        $data = $request->validate([...$this->structureRules($company, $unit, false), 'base_version' => ['required', 'integer']]);
        $saved = $this->setup->saveStructure($company, $found, $data, $request->user());

        return response()->json(['data' => $this->presenter->structure($saved, $this->setup->lines($company, $saved))]);
    }

    public function activateStructure(Request $request, string $organization, string $structure): JsonResponse
    {
        return $this->structureStatus($request, $organization, $structure, 'active');
    }

    public function archiveStructure(Request $request, string $organization, string $structure): JsonResponse
    {
        return $this->structureStatus($request, $organization, $structure, 'archived');
    }

    private function structureStatus(Request $request, string $organization, string $structure, string $status): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $unit);
        $found = $this->office->find(FeeStructure::class, $company, $structure, 'structure');
        $version = (int) $request->validate(['base_version' => ['required', 'integer']])['base_version'];
        $saved = $this->setup->setStructureStatus($company, $found, $status, $version, $request->user());

        return response()->json(['data' => $this->presenter->structure($saved, $this->setup->lines($company, $saved))]);
    }

    /** @return array<string, mixed> */
    private function headRules(Organization $company, ?FeeHead $head): array
    {
        $required = $head === null ? 'required' : 'sometimes';
        $codes = $this->office->query(FeeHead::class, $company)->when($head, fn ($query) => $query->whereKeyNot($head->getKey()))->pluck('code')->map(fn ($code) => mb_strtolower($code))->all();

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', function (string $attribute, mixed $value, \Closure $fail) use ($codes) {
                if (in_array(mb_strtolower((string) $value), $codes, true)) {
                    $fail(__('education_fees::fees.validation.code_taken'));
                }
            }],
            'name' => [$required, 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:150'],
            'frequency' => [$required, Rule::in(FeeHead::FREQUENCIES)],
            'income_key' => [$required, 'string', 'max:60'],
            'tax_code_id' => ['nullable', 'string', 'size:26'],
            'sibling_discount' => ['sometimes', 'boolean'],
            'late_fine' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /** @return array<string, mixed> */
    private function structureRules(Organization $company, Organization $unit, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $heads = $this->office->query(FeeHead::class, $company)->pluck('id')->all();
        $sessions = array_column(app(AcademicDirectory::class)->sessions($company), 'id');

        return [
            'name' => [$required, 'string', 'max:150'],
            'session_id' => [$required, Rule::in($sessions)],
            'unit_id' => ['nullable', Rule::in($this->unitIds($unit))],
            'program_id' => ['nullable', 'string', 'size:26'],
            'level_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array:head_id,amount_minor,months'],
            'lines.*.head_id' => ['required', 'distinct', Rule::in($heads)],
            'lines.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.months' => ['nullable', 'array', 'max:12'],
            'lines.*.months.*' => ['integer', 'between:1,12', 'distinct'],
        ];
    }
}
