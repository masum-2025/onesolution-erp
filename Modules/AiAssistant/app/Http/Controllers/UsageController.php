<?php

namespace Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AiAssistant\Services\Understanding;

/** SC-4: how much AI the company used this month and on each of the last days (counts only). */
class UsageController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private Understanding $understanding, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function show(string $organization): JsonResponse
    {
        $company = $this->companyOf($this->findVisible($organization));
        Gate::authorize('ai_assistant.manage', $company);
        $context = $this->contexts->forOrganization($company);

        return response()->json(['data' => [
            'company' => $company->displayName(),
            'limit' => (int) $this->rules->get('ai_assistant.monthly_calls', $context),
            'used' => $this->understanding->usedThisMonth($company),
            'left' => $this->understanding->left($company),
            'model' => (string) $this->rules->get('ai_assistant.model', $context),
            'key_set' => filled(config('ai.api_key')),
            'days' => $this->understanding->history($company),
        ]]);
    }

    /** Usage is kept per company: a branch shows its company's. */
    private function companyOf(Organization $unit): Organization
    {
        $companies = [OrganizationType::Company, OrganizationType::Personal];
        if (in_array($unit->type, $companies, true)) {
            return $unit;
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', array_map(fn (OrganizationType $type) => $type->value, $companies))
            ->orderByDesc('depth')->first() ?? abort(422, __('ai_assistant::module.not_company'));
    }
}
