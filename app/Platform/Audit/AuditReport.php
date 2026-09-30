<?php

namespace App\Platform\Audit;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Audit reports for advanced_audit (Phase 9-1): entries per day, the most
 * frequent actions and the most active people, over a limited period.
 * Counted in PHP from a streamed query, so it runs the same on MySQL and
 * PostgreSQL (no vendor date functions).
 */
class AuditReport
{
    private const TOP = 10;

    public function __construct(private AuditQuery $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $days = [];
        for ($day = $from->setTimezone($timezone)->startOfDay(); $day->lt($to); $day = $day->addDay()) {
            $days[$day->toDateString()] = 0;
        }

        $actions = [];
        $actors = [];
        $support = 0;
        $money = 0;
        $total = 0;

        $rows = $this->audit->forOrganization($organization, ['from' => $from, 'to' => $to])
            ->toBase()
            ->select(['id', 'created_at', 'action', 'actor_user_id'])
            ->lazyById(1000, 'id');

        foreach ($rows as $row) {
            $total++;
            $day = CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone($timezone)->toDateString();
            $days[$day] = ($days[$day] ?? 0) + 1;
            $actions[$row->action] = ($actions[$row->action] ?? 0) + 1;
            if ($row->actor_user_id !== null) {
                $actors[$row->actor_user_id] = ($actors[$row->actor_user_id] ?? 0) + 1;
            }
            $support += str_starts_with($row->action, 'support.') ? 1 : 0;
            $money += $this->audit->isMoney($row->action) ? 1 : 0;
        }

        arsort($actions);
        arsort($actors);
        $topActors = array_slice($actors, 0, self::TOP, true);
        $names = User::query()->whereKey(array_keys($topActors))->pluck('name', 'id');

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'timezone' => $timezone,
            'total' => $total,
            'support' => $support,
            'money' => $money,
            'people' => count($actors),
            'days' => array_map(fn (string $date, int $count) => ['date' => $date, 'count' => $count], array_keys($days), $days),
            'actions' => array_map(
                fn (string $action, int $count) => ['action' => $action, 'label' => $this->audit->label($action), 'count' => $count],
                array_keys(array_slice($actions, 0, self::TOP, true)),
                array_slice($actions, 0, self::TOP, true),
            ),
            'actors' => array_map(
                fn (string $id, int $count) => ['id' => $id, 'name' => $names[$id] ?? null, 'count' => $count],
                array_keys($topActors),
                $topActors,
            ),
        ];
    }
}
