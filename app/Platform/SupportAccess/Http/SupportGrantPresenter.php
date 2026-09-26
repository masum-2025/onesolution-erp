<?php

namespace App\Platform\SupportAccess\Http;

use App\Models\User;
use App\Platform\SupportAccess\Models\SupportGrant;
use Illuminate\Support\Collection;

/**
 * One support grant for the client's approval screen and the partner console.
 */
class SupportGrantPresenter
{
    /**
     * @param  Collection<int, SupportGrant>  $grants
     * @return list<array<string, mixed>>
     */
    public function many(Collection $grants): array
    {
        $people = User::query()
            ->whereKey($grants->pluck('requested_by')->merge($grants->pluck('decided_by'))->filter()->unique()->values())
            ->pluck('name', 'id');

        return $grants->map(fn (SupportGrant $grant) => $this->one($grant, $people))->values()->all();
    }

    /**
     * @param  Collection<string, string>|null  $people
     * @return array<string, mixed>
     */
    public function one(SupportGrant $grant, ?Collection $people = null): array
    {
        $people ??= User::query()->whereKey(array_filter([$grant->requested_by, $grant->decided_by]))->pluck('name', 'id');

        return [
            'id' => $grant->getKey(),
            'organization' => ['id' => $grant->organization_id, 'name' => $grant->organization?->displayName()],
            'partner' => ['id' => $grant->partner_id, 'name' => $grant->partner?->name],
            'requested_by' => ['id' => $grant->requested_by, 'name' => $people[$grant->requested_by] ?? null],
            'reason' => $grant->reason,
            'severity' => $grant->severity->value,
            'access' => $grant->access,
            'duration_minutes' => $grant->duration_minutes,
            // "approved" but past its time reads as expired even before the scheduler runs.
            'status' => $grant->status->value === 'approved' && ! $grant->isUsable() ? 'expired' : $grant->status->value,
            'auto_approved' => $grant->auto_approved,
            'decided_by' => $grant->decided_by === null ? null : ['id' => $grant->decided_by, 'name' => $people[$grant->decided_by] ?? null],
            'decision_reason' => $grant->decision_reason,
            'requested_at' => $grant->created_at?->toIso8601String(),
            'starts_at' => $grant->starts_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'ended_at' => $grant->ended_at?->toIso8601String(),
        ];
    }
}
