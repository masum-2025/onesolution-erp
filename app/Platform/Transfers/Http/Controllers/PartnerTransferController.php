<?php

namespace App\Platform\Transfers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Transfers\Exceptions\TransferException;
use App\Platform\Transfers\Http\Requests\TransferCodeRequest;
use App\Platform\Transfers\Http\Requests\TransferDecisionRequest;
use App\Platform\Transfers\Http\TransferPresenter;
use App\Platform\Transfers\Models\ClientTransfer;
use App\Platform\Transfers\Models\TransferCode;
use App\Platform\Transfers\Services\TransferCodes;
use App\Platform\Transfers\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: clients moving in (codes to hand out, requests to accept)
 * and moving out (for information: the client decides). Owners and sales
 * staff make codes; owners accept or reject.
 */
class PartnerTransferController extends Controller
{
    use PartnerConsole;

    public function __construct(private TransferCodes $codes, private TransferService $transfers, private TransferPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $partner = $this->partner();
        $direction = $request->query('direction') === 'outgoing' ? 'from_partner_id' : 'to_partner_id';

        $transfers = ClientTransfer::query()->where($direction, $partner->getKey())->latest()->limit(100)->get();
        $codes = TransferCode::query()->where('partner_id', $partner->getKey())->latest()->limit(50)->get();

        return response()->json([
            'data' => $transfers->map(fn (ClientTransfer $transfer) => $this->presenter->transfer($transfer))->values(),
            'codes' => $codes->map(fn (TransferCode $code) => [
                'id' => $code->getKey(),
                'label' => $code->label,
                'hint' => $code->hint,
                'status' => $code->status(),
                'expires_at' => $code->expires_at->toIso8601String(),
                'created_at' => $code->created_at?->toIso8601String(),
            ])->values(),
            'can_create_codes' => $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Sales),
            'can_decide' => $this->hasRole(PartnerUserRole::Owner),
        ]);
    }

    public function storeCode(TransferCodeRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Sales);
        $created = $this->codes->create($this->partner(), $request->validated('label'), $request->user());

        return response()->json([
            // Shown once: only a hash is kept.
            'data' => ['code' => $created['code'], 'expires_at' => $created['record']->expires_at->toIso8601String()],
            'message' => __('transfers.messages.code_created'),
        ], 201);
    }

    public function revokeCode(Request $request, string $code): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Sales);
        $record = TransferCode::query()->where('partner_id', $this->partner()->getKey())->whereKey($code)->first() ?? throw TransferException::notFound();
        $this->codes->revoke($record, $request->user());

        return response()->json(['message' => __('transfers.messages.code_revoked')]);
    }

    public function accept(TransferDecisionRequest $request, string $transfer): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $record = $this->incoming($transfer);

        return response()->json([
            'data' => $this->presenter->transfer($this->transfers->accept($record, $request->user(), $request->validated('note'))),
            'message' => __('transfers.messages.accepted', ['client' => $record->organization->displayName()]),
        ]);
    }

    public function reject(TransferDecisionRequest $request, string $transfer): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);

        return response()->json([
            'data' => $this->presenter->transfer($this->transfers->reject($this->incoming($transfer), $request->user(), $request->validated('note'))),
            'message' => __('transfers.messages.rejected'),
        ]);
    }

    private function incoming(string $id): ClientTransfer
    {
        return ClientTransfer::query()->where('to_partner_id', $this->partner()->getKey())->whereKey($id)->first()
            ?? throw TransferException::notFound();
    }
}
