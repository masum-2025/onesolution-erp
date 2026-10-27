<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Recruitment\Http\Controllers\Concerns\FindsPlace;
use Modules\Recruitment\Http\Requests\CareersRequest;
use Modules\Recruitment\Services\Careers;

/** The company's careers page: its address and link, and whether public applying is on (rule); the address set with recruitment.manage. */
class CareersController extends Controller
{
    use FindsPlace;

    public function __construct(private Careers $careers, private LinkBuilder $links) {}

    public function show(string $organization, RuleResolver $rules, RuleContextFactory $contexts): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['recruitment.view', 'recruitment.manage'], $unit), 403);

        return response()->json(['data' => [
            ...$this->address($company),
            'public_apply' => (bool) $rules->get('recruitment.public_apply', $contexts->forOrganization($company)),
            'can_manage' => Gate::allows('recruitment.manage', $company),
        ]]);
    }

    public function update(CareersRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('recruitment.manage', $company);
        $this->careers->setSlug($company, $request->validated('slug'), $request->user());

        return response()->json(['data' => $this->address($company)]);
    }

    /**
     * @return array{slug: string|null, url: string|null}
     */
    private function address(Organization $company): array
    {
        $slug = $this->careers->slug($company);

        return ['slug' => $slug, 'url' => $slug === null ? null : $this->links->to("/careers/{$slug}", $company->partner, $company)];
    }
}
