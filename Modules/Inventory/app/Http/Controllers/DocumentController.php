<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Http\Controllers\Concerns\FindsInventory;
use Modules\Inventory\Http\InventoryPresenter;
use Modules\Inventory\Http\Requests\DocumentRequest;
use Modules\Inventory\Models\Document;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Documents;
use Modules\Inventory\Services\Inventories;

/**
 * Receipts, issues, transfers and adjustments of the warehouses at the unit
 * in the address and below: read (inventory.view), written, posted and
 * dispatched (inventory.manage at the warehouse's unit), received
 * (inventory.manage at the receiving warehouse's unit), approved or rejected
 * (inventory.approve; never one's own).
 */
class DocumentController extends Controller
{
    use FindsInventory;

    public function __construct(private Inventories $inventories, private Documents $documents, private InventoryPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $filters = $request->validate(['type' => ['nullable', 'in:'.implode(',', Document::TYPES)], 'status' => ['nullable', 'string', 'max:20']]);
        $warehouses = $this->warehouseIds($unit, $company);
        $query = $this->inventories->query(Document::class, $company)
            ->where(fn ($query) => $query->whereIn('warehouse_id', $warehouses)->orWhereIn('to_warehouse_id', $warehouses))
            ->orderByDesc('document_date')->orderByDesc('created_at')->limit(300);
        foreach (['type', 'status'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return response()->json(['data' => $query->get()->map(fn (Document $document) => $this->presenter->document($document))->values(), 'meta' => ['currency' => $this->inventories->currency($company)]]);
    }

    public function store(DocumentRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $warehouse = $this->warehouseIn($unit, $company, $request->validated('warehouse_id'));
        Gate::authorize('inventory.manage', $this->unitOf($warehouse->unit_id));
        if ($request->validated('to_warehouse_id')) {
            $this->found(Warehouse::class, $company, $request->validated('to_warehouse_id'), 'warehouse');
        }

        return response()->json(['data' => $this->full($company, $this->documents->create($company, $request->validated(), $request->user()))], 201);
    }

    public function show(string $organization, string $document): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);

        return response()->json(['data' => $this->full($company, $this->documentIn($unit, $company, $document))]);
    }

    public function update(DocumentRequest $request, string $organization, string $document): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->documentIn($unit, $company, $document);
        Gate::authorize('inventory.manage', $this->unitOf($this->warehouseIn($unit, $company, $request->validated('warehouse_id') ?? $found->warehouse_id)->unit_id));
        $data = $request->validated();

        return response()->json(['data' => $this->full($company, $this->documents->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $document): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->documentIn($unit, $company, $document);
        Gate::authorize('inventory.manage', $this->unitOf($this->found(Warehouse::class, $company, $found->warehouse_id, 'warehouse')->unit_id));
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);
        $this->documents->delete($company, $found, (int) $request->input('base_version'), $request->user());

        return response()->json(null, 204);
    }

    public function step(DocumentRequest $request, string $organization, string $document, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->documentIn($unit, $company, $document);
        $source = $this->unitOf($this->found(Warehouse::class, $company, $found->warehouse_id, 'warehouse')->unit_id);
        $permission = ['post' => 'inventory.manage', 'dispatch' => 'inventory.manage', 'cancel' => 'inventory.manage', 'receive' => 'inventory.manage',
            'approve' => 'inventory.approve', 'reject' => 'inventory.approve'][$step] ?? throw InventoryException::unknownStep();
        $at = $step === 'receive' ? $this->unitOf($this->found(Warehouse::class, $company, (string) $found->to_warehouse_id, 'warehouse')->unit_id) : $source;
        Gate::authorize($permission, $at);
        $version = (int) $request->validated('base_version');
        $actor = $request->user();

        $changed = match ($step) {
            'post' => $this->documents->post($company, $found, $version, $actor),
            'dispatch' => $this->documents->dispatch($company, $found, $version, $actor),
            'receive' => $this->documents->receive($company, $found, $version, (array) $request->validated('received', []), $actor),
            'approve' => $this->documents->approve($company, $found, $version, $actor),
            'reject' => $this->documents->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'cancel' => $this->documents->cancel($company, $found, $version, $actor),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    private function documentIn(Organization $unit, Organization $company, string $id): Document
    {
        $warehouses = $this->warehouseIds($unit, $company);
        $document = $this->inventories->query(Document::class, $company)->whereKey($id)->first();
        if ($document === null || (! in_array($document->warehouse_id, $warehouses, true) && ! in_array($document->to_warehouse_id, $warehouses, true))) {
            throw InventoryException::notFound('document');
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Document $document): array
    {
        $source = $this->unitOf($this->found(Warehouse::class, $company, $document->warehouse_id, 'warehouse')->unit_id);
        $manages = Gate::allows('inventory.manage', $source);
        $approves = Gate::allows('inventory.approve', $source) && ! in_array(auth()->id(), [$document->created_by, $document->submitted_by], true);
        $draft = $document->status === Document::DRAFT;
        $receives = $document->status === Document::IN_TRANSIT && $document->to_warehouse_id !== null
            && Gate::allows('inventory.manage', $this->unitOf($this->found(Warehouse::class, $company, $document->to_warehouse_id, 'warehouse')->unit_id));

        return $this->presenter->document($document, $this->documents->linesOf($company, $document), [
            'edit' => $draft && $manages,
            'post' => $draft && $manages && $document->type !== 'transfer',
            'dispatch' => $draft && $manages && $document->type === 'transfer',
            'receive' => $receives,
            'approve' => $document->status === Document::PENDING && $approves,
            'reject' => $document->status === Document::PENDING && $approves,
            'cancel' => in_array($document->status, [Document::DRAFT, Document::PENDING], true) && $manages,
        ]);
    }
}
