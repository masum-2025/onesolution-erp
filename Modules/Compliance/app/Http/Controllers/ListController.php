<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Compliance\Http\CompliancePresenter;
use Modules\Compliance\Http\Controllers\Concerns\FindsPlace;
use Modules\Compliance\Http\Requests\ListItemRequest;
use Modules\Compliance\Services\Lists;

/**
 * The register's lists (services, kinds of audit, providers) usable at the
 * unit in the address: its own and those kept above it. A group or a
 * company keeps them (compliance.manage); switched off, never removed.
 */
class ListController extends Controller
{
    use FindsPlace;

    public function __construct(private Lists $lists, private CompliancePresenter $presenter) {}

    public function index(Request $request, string $organization, string $kind): JsonResponse
    {
        $this->assertKind($kind);
        $unit = $this->unit($organization, 'compliance.view');

        return response()->json(['data' => $this->lists->of($unit, $kind, $request->boolean('all'))
            ->map(fn ($item) => $this->presenter->item($kind, $item, $unit))->values()]);
    }

    public function store(ListItemRequest $request, string $organization, string $kind): JsonResponse
    {
        $this->assertKind($kind);
        $unit = $this->unit($organization, 'compliance.manage');
        $item = $this->lists->save($unit, $kind, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->item($kind, $item, $unit)], 201);
    }

    public function update(ListItemRequest $request, string $organization, string $kind, string $item): JsonResponse
    {
        $this->assertKind($kind);
        $unit = $this->unit($organization, 'compliance.manage');
        $found = $this->lists->find($unit, $kind, $item);
        $saved = $this->lists->save($unit, $kind, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->item($kind, $saved, $unit)]);
    }

    /** The usual services or kinds of audit (from the rules), those not there yet. */
    public function defaults(Request $request, string $organization, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['services', 'types'], true), 404);
        $unit = $this->unit($organization, 'compliance.manage');

        return response()->json(['data' => ['added' => $this->lists->addDefaults($unit, $kind, $request->user())]]);
    }

    private function assertKind(string $kind): void
    {
        abort_unless(array_key_exists($kind, Lists::KINDS), 404);
    }
}
