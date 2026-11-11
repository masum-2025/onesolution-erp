<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Http\Requests\InpatientActionRequest;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\NursingNote;
use Modules\Inpatient\Models\Round;
use Modules\Inpatient\Models\TestOrder;
use Modules\Inpatient\Services\Admissions;
use Modules\Inpatient\Services\Care;
use Modules\Inpatient\Services\InpatientStore;

/**
 * Care notes of a stay: read by those who give care (inpatient.round,
 * .nurse or .discharge; never the desk alone), a doctor limited to their own
 * patients seeing only theirs. Round notes and test orders are written with
 * inpatient.round, nursing notes with inpatient.nurse.
 */
class CareController extends Controller
{
    use FindsInpatient;

    public const READERS = ['inpatient.round', 'inpatient.nurse', 'inpatient.discharge'];

    public function __construct(private InpatientStore $store, private Admissions $admissions, private Care $care) {}

    public function show(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission);
        $rounds = $this->store->query(Round::class, $company)->where('admission_id', $found->getKey())->orderByDesc('noted_at')->get();
        $notes = $this->store->query(NursingNote::class, $company)->where('admission_id', $found->getKey())->orderByDesc('noted_at')->get();
        $orders = $this->store->query(TestOrder::class, $company)->where('admission_id', $found->getKey())->orderByDesc('ordered_at')->get();
        $names = User::query()->whereKey([...$rounds->pluck('doctor_user_id'), ...$notes->pluck('noted_by'), ...$orders->pluck('ordered_by')])->pluck('name', 'id');
        $lab = $this->care->labOn($company);
        $catalogue = $lab ? app(TestOrders::class)->catalogue($company) : [];
        $testNames = array_column($catalogue, 'name', 'code');

        return response()->json(['data' => [
            'rounds' => $rounds->map(fn (Round $round) => ['id' => $round->getKey(), 'note' => $round->note, 'by' => $names[(string) $round->doctor_user_id] ?? null, 'at' => $round->noted_at->toIso8601String()])->values(),
            'nursing_notes' => $notes->map(fn (NursingNote $note) => [
                'id' => $note->getKey(), 'vitals' => $note->vitals ?? (object) [], 'note' => $note->note, 'medicines_given' => $note->medicines_given ?? [],
                'by' => $names[(string) $note->noted_by] ?? null, 'at' => $note->noted_at->toIso8601String(),
            ])->values(),
            'test_orders' => $orders->map(fn (TestOrder $order) => [
                'id' => $order->getKey(), 'tests' => array_map(fn ($code) => ['code' => $code, 'name' => $testNames[$code] ?? $code], $order->codes),
                'lab_order_id' => $order->lab_order_id, 'lab_order_number' => $order->lab_order_number, 'by' => $names[(string) $order->ordered_by] ?? null, 'at' => $order->ordered_at->toIso8601String(),
            ])->values(),
            'lab' => $lab,
            'catalogue' => $lab && Gate::allows('inpatient.round', $unit) ? $catalogue : [],
            'can' => ['round' => Gate::allows('inpatient.round', $unit), 'nurse' => Gate::allows('inpatient.nurse', $unit), 'discharge' => Gate::allows('inpatient.discharge', $unit)],
        ]]);
    }

    public function round(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.round');
        $data = $request->validated();
        $round = $this->care->addRound($company, $found, $data['note'], $data['op_id'] ?? null, $request->user());

        return response()->json(['data' => ['id' => $round->getKey(), 'at' => $round->noted_at->toIso8601String()]], $round->wasRecentlyCreated ? 201 : 200);
    }

    public function nursingNote(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.nurse');
        $note = $this->care->addNursingNote($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $note->getKey(), 'at' => $note->noted_at->toIso8601String()]], $note->wasRecentlyCreated ? 201 : 200);
    }

    public function tests(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.round');
        $data = $request->validated();
        $order = $this->care->orderTests($company, $found, $data['codes'], $data['op_id'] ?? null, $request->user());

        return response()->json(['data' => ['id' => $order->getKey(), 'lab_order_id' => $order->lab_order_id, 'lab_order_number' => $order->lab_order_number]], $order->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * The stay (404 when not visible), and the permission: one of the care readers when none is named.
     *
     * @return array{0: Organization, 1: Organization, 2: Admission}
     */
    private function stay(Request $request, string $organization, string $admission, ?string $permission = null): array
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $found = $this->admissions->visible($company, $unit, $request->user(), $admission);
        if ($permission !== null) {
            Gate::authorize($permission, $unit);
        } elseif (! Gate::any(self::READERS, $unit)) {
            throw InpatientException::because('care_only', 403);
        }

        return [$unit, $company, $found];
    }
}
