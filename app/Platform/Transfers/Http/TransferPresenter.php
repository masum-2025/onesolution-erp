<?php

namespace App\Platform\Transfers\Http;

use App\Models\User;
use App\Platform\Transfers\Models\ClientTransfer;

/**
 * A client transfer for the client's provider page and the partner console.
 */
class TransferPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function transfer(ClientTransfer $transfer): array
    {
        $transfer->loadMissing(['organization', 'fromPartner', 'toPartner']);
        $people = User::query()->whereKey(array_filter([$transfer->requested_by, $transfer->decided_by]))->pluck('name', 'id');

        return [
            'id' => $transfer->getKey(),
            'status' => $transfer->status,
            'client' => ['id' => $transfer->organization_id, 'name' => $transfer->organization?->displayName()],
            'from' => ['id' => $transfer->from_partner_id, 'name' => $transfer->fromPartner?->name],
            'to' => ['id' => $transfer->to_partner_id, 'name' => $transfer->toPartner?->name, 'house' => (bool) $transfer->toPartner?->is_house],
            'reason' => $transfer->reason,
            'by_platform' => $transfer->by_platform,
            'requested_by' => $transfer->requested_by === null ? null : ['id' => $transfer->requested_by, 'name' => $people[$transfer->requested_by] ?? null],
            'consented_at' => $transfer->consented_at?->toIso8601String(),
            'decided_by' => $transfer->decided_by === null ? null : ['id' => $transfer->decided_by, 'name' => $people[$transfer->decided_by] ?? null],
            'decision_note' => $transfer->decision_note,
            'summary' => $transfer->summary,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'completed_at' => $transfer->completed_at?->toIso8601String(),
        ];
    }
}
