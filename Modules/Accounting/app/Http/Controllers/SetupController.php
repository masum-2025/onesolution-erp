<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Charts\ChartTemplates;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\SetupRequest;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\ChartOfAccounts;

/**
 * Whether the company's books are set up, and setting them up once: a chart
 * template (the sector's suggestion first) and the first fiscal year.
 */
class SetupController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private ChartOfAccounts $chart) {}

    public function show(string $organization, ChartTemplates $templates, RuleResolver $rules, RuleContextFactory $contexts): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => [
            'set_up' => $this->books->isSetUp($company),
            'currency' => $this->books->currency($company),
            'suggested_template' => $this->chart->suggestedTemplate($company),
            'templates' => array_map(fn (string $key) => ['key' => $key, 'label' => __("accounting::accounting.templates.{$key}")], $templates->keys()),
            'fiscal_year_start' => $rules->get('accounting.fiscal_year_start', $contexts->forOrganization($company)),
            'can_set_up' => Gate::allows('accounting.manage', $company),
        ]]);
    }

    public function store(SetupRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);

        $result = $this->chart->setUp($company, $request->validated('template'), $request->validated('first_year_starts_on'), $request->user());

        return response()->json(['data' => $result], 201);
    }
}
