<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\CuttingRequest;
use Modules\FactoryErp\Http\Requests\IssueRequest;
use Modules\FactoryErp\Http\Requests\ReceiptRequest;
use Modules\FactoryErp\Models\Cutting;
use Modules\FactoryErp\Models\Issue;
use Modules\FactoryErp\Models\Receipt;
use Modules\FactoryErp\Services\Cuttings;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Issues;
use Modules\FactoryErp\Services\Progress;
use Modules\FactoryErp\Services\Receipts;

/**
 * An order on the floor: materials issued to it and returned from it, and
 * its finished pieces into a store (factory_erp.issue); the lays cut for
 * it (factory_erp.produce); where its pieces are per size and colour.
 * Read with factory_erp.view at the order's unit or one above it.
 */
class FloorController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private Issues $issues,
        private Cuttings $cuttings,
        private Progress $progress,
        private Receipts $receipts,
        private FactoryPresenter $presenter,
    ) {}

    public function issues(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $issues = $this->issues->of($company, $this->orderIn($unit, $company, $order));
        $lines = $this->issues->lines($company, $issues->modelKeys());

        return response()->json(['data' => $issues->map(fn (Issue $issue) => $this->presenter->issue($issue, $lines[$issue->getKey()] ?? collect()))->values()]);
    }

    public function issue(IssueRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('factory_erp.issue', $unit);
        $issue = $this->issues->record($company, $unit, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->issue($issue, $this->issues->lines($company, [$issue->getKey()])[$issue->getKey()] ?? collect())],
            $issue->wasRecentlyCreated ? 201 : 200);
    }

    public function cuttings(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $found = $this->orderIn($unit, $company, $order);
        $cuttings = $this->cuttings->of($company, $found);
        $lines = $this->cuttings->lines($company, $cuttings->modelKeys());

        return response()->json([
            'data' => $cuttings->map(fn (Cutting $cutting) => $this->presenter->cutting($cutting, $lines[$cutting->getKey()] ?? collect()))->values(),
            'meta' => ['cut' => (object) $this->cuttings->cut($company, $found)],
        ]);
    }

    public function cut(CuttingRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('factory_erp.produce', $unit);
        $cutting = $this->cuttings->record($company, $unit, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->cutting($cutting, $this->cuttings->lines($company, [$cutting->getKey()])[$cutting->getKey()] ?? collect())],
            $cutting->wasRecentlyCreated ? 201 : 200);
    }

    /** Where the order's pieces are per size and colour, and its work in progress. */
    public function progress(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $found = $this->orderIn($unit, $company, $order);
        $cells = $this->progress->of($company, $found);

        return response()->json(['data' => [
            'cells' => (object) $cells,
            'totals' => Progress::totals($cells),
            'wip_minor' => $this->receipts->workInProgress($company, $found),
            'currency' => rescue(fn () => $this->floor->currency($company), null, false),
        ]]);
    }

    public function receipts(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $receipts = $this->receipts->of($company, $this->orderIn($unit, $company, $order));
        $lines = $this->receipts->lines($company, $receipts->modelKeys());

        return response()->json(['data' => $receipts->map(fn (Receipt $receipt) => $this->presenter->receipt($receipt, $lines[$receipt->getKey()] ?? collect()))->values()]);
    }

    public function receive(ReceiptRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('factory_erp.issue', $unit);
        $receipt = $this->receipts->record($company, $unit, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->receipt($receipt, $this->receipts->lines($company, [$receipt->getKey()])[$receipt->getKey()] ?? collect())],
            $receipt->wasRecentlyCreated ? 201 : 200);
    }

    public function voidCutting(Request $request, string $organization, string $order, string $cutting): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        $lay = $this->floor->query(Cutting::class, $company)->whereKey($cutting)->where('order_id', $found->getKey())->first()
            ?? throw FactoryException::notFound('cutting');
        Gate::authorize('factory_erp.produce', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $voided = $this->cuttings->void($company, $lay, (int) $data['base_version'], trim($data['reason']), $request->user());

        return response()->json(['data' => $this->presenter->cutting($voided, $this->cuttings->lines($company, [$voided->getKey()])[$voided->getKey()] ?? collect())]);
    }
}
