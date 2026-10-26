<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Http\Controllers\Concerns\FindsPlace;
use Modules\Expenses\Http\ExpensesPresenter;
use Modules\Expenses\Http\Requests\CategoryRequest;
use Modules\Expenses\Services\Categories;

/**
 * The company's kinds of expense: seen by anyone of the module, changed by
 * those who oversee expenses (expenses.manage, audited).
 */
class CategoryController extends Controller
{
    use FindsPlace;

    public function __construct(private Categories $categories, private ExpensesPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['expenses.claim', 'expenses.approve', 'expenses.pay', 'expenses.manage'], $unit), 403);

        return response()->json([
            'data' => $this->categories->of($company)->map(fn ($category) => $this->presenter->category($category))->values(),
            'meta' => ['can_manage' => Gate::allows('expenses.manage', $company), 'posting_keys' => Categories::POSTING_KEYS],
        ]);
    }

    public function store(CategoryRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('expenses.manage', $company);

        return response()->json(['data' => $this->presenter->category($this->categories->save($company, null, $request->validated(), $request->user()))], 201);
    }

    public function update(CategoryRequest $request, string $organization, string $category): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('expenses.manage', $company);
        $found = $this->categoryIn($company, $category);

        return response()->json(['data' => $this->presenter->category($this->categories->save($company, $found, $request->validated(), $request->user()))]);
    }

    public function defaults(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('expenses.manage', $company);
        $added = $this->categories->addDefaults($company, $request->user());

        return response()->json(['data' => ['added' => $added], 'message' => trans_choice('expenses::expenses.categories_added', $added, ['count' => $added])]);
    }
}
