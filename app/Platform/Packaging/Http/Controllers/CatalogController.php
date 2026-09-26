<?php

namespace App\Platform\Packaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Packaging\Http\PlanPresenter;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public plans and sector packages, for pickers and plan comparison.
 */
class CatalogController extends Controller
{
    public function __construct(private PlanPresenter $presenter) {}

    /**
     * Business plans, or personal plans with ?audience=personal.
     */
    public function plans(Request $request, PlanCatalog $plans): JsonResponse
    {
        $audience = $request->query('audience') === PlanCatalog::PERSONAL ? PlanCatalog::PERSONAL : PlanCatalog::BUSINESS;
        $public = array_filter($plans->all(), fn ($plan) => $plan->public && $plan->audience === $audience);

        return response()->json(['data' => array_values(array_map(fn ($plan) => $this->presenter->plan($plan), $public))]);
    }

    public function sectors(SectorCatalog $sectors): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn ($package) => $this->presenter->sector($package), $sectors->all()))]);
    }
}
