<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Services\Customers as BooksCustomers;
use Modules\Accounting\Services\TaxCodes;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Models\Field;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Fields;
use Modules\Crm\Services\Pipelines;

/**
 * What the CRM screens need once (crm.view): currency, the company's
 * pipelines and extra fields, the people work can be given to, VAT codes
 * for quotes (when Accounting keeps the books), and whether Inventory items
 * and invoicing are there.
 */
class SetupController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Pipelines $pipelines, private Fields $fields, private CrmPresenter $presenter) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        $modules = app(ModuleResolver::class);
        $books = $modules->isEnabled('accounting', $company) && app(BooksCustomers::class)->available($company);
        $people = OrganizationMembership::query()->whereIn('organization_id', $this->crm->subtreeIds($company))->where('status', MembershipStatus::Active)->pluck('user_id')->unique();
        $rules = app(RuleResolver::class);
        $context = app(RuleContextFactory::class)->forOrganization($company);

        return response()->json(['data' => [
            'currency' => $this->crm->currency($company),
            'today' => $this->crm->today($company)->toDateString(),
            'pipelines' => $this->pipelines->all($company)->map(fn ($pipeline) => $this->presenter->pipeline($pipeline))->values(),
            'fields' => collect(Field::ENTITIES)->mapWithKeys(fn (string $entity) => [$entity => $this->fields->of($company, $entity)->map(fn (Field $field) => $this->presenter->field($field))->values()]),
            'people' => User::query()->whereKey($people->all())->orderBy('name')->get(['id', 'name'])->map(fn (User $user) => ['id' => $user->getKey(), 'name' => $user->name])->values(),
            'tax_codes' => $books ? app(TaxCodes::class)->salesCodes($company) : [],
            'inventory' => $modules->isEnabled('inventory', $company),
            'books' => $books,
            'quote_prices_include_tax' => (bool) $rules->get('crm.quote_prices_include_tax', $context),
            'consent_required' => (bool) $rules->get('crm.marketing_consent_required', $context),
        ]]);
    }
}
