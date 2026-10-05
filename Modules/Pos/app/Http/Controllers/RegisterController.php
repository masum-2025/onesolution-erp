<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pos\Http\Controllers\Concerns\FindsPos;
use Modules\Pos\Http\PosPresenter;
use Modules\Pos\Http\Requests\PosRequest;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Counters;
use Modules\Pos\Services\Sessions;
use Modules\Pos\Services\Tills;

/**
 * Counters at the unit in the address and below: read (pos.view or
 * pos.sell), set up (pos.manage at the company), their catalogue (pos.sell
 * at the counter's branch) and their open shift.
 */
class RegisterController extends Controller
{
    use FindsPos;

    public function __construct(private Tills $tills, private Counters $counters, private Sessions $sessions, private PosPresenter $presenter, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.view', 'pos.sell'], $unit), 403);
        $registers = $this->tills->query(Register::class, $company)->whereIn('unit_id', $this->tills->subtreeIds($unit))->orderBy('code')->get();

        return response()->json([
            'data' => $registers->map(fn (Register $register) => [
                ...$this->presenter->register($register),
                'session' => ($open = $this->sessions->current($company, $register)) === null ? null : $this->presenter->session($open),
            ])->values(),
            'meta' => ['currency' => $this->tills->currency($company)],
        ]);
    }

    public function store(PosRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('pos.manage', $company);

        return response()->json(['data' => $this->presenter->register($this->counters->save($company, null, null, $request->validated(), $request->user()))], 201);
    }

    public function update(PosRequest $request, string $organization, string $register): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pos.manage', $company);
        $found = $this->registerIn($unit, $company, $register);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->register($this->counters->save($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    /** What the counter sells: items, prices, tax rates, on hand; units; the selling rules at its branch. */
    public function catalogue(string $organization, string $register): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->registerIn($unit, $company, $register);
        Gate::authorize('pos.sell', $this->unitOf($found->unit_id));

        $context = $this->contexts->forOrganization($this->unitOf($found->unit_id));

        return response()->json(['data' => $this->counters->catalogue($company, $found), 'meta' => [
            'currency' => $this->tills->currency($company),
            'prices_include_tax' => (bool) $this->rules->get('pos.prices_include_tax', $context),
            'max_discount_percent' => (string) $this->rules->get('pos.max_discount_percent', $context),
            'offline_sales' => (bool) $this->rules->get('pos.offline_sales', $context),
        ]]);
    }
}
