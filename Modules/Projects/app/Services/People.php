<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Who may be given a task or lead a project at a unit: the people who hold
 * projects.work or projects.manage there (owners included; portal users
 * never). Read once per unit and request.
 */
class People
{
    /** @var array<string, Collection<int, User>> */
    private array $memo = [];

    public function __construct(private Recipients $recipients) {}

    /**
     * @return Collection<int, User>
     */
    public function assignable(Organization $unit): Collection
    {
        return $this->memo[$unit->getKey()] ??= $this->recipients->holding('projects.work', $unit)
            ->merge($this->recipients->holding('projects.manage', $unit))
            ->unique(fn (User $user) => $user->getKey())
            ->sortBy(fn (User $user) => mb_strtolower((string) $user->name))
            ->values();
    }

    /**
     * @param  list<string>  $userIds
     */
    public function allAssignable(Organization $unit, array $userIds): bool
    {
        $ids = $this->assignable($unit)->map(fn (User $user) => $user->getKey())->all();

        return array_diff($userIds, $ids) === [];
    }

    /**
     * Names of people by id (for screens and exports).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function names(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereKey(array_values(array_unique($ids)))->pluck('name', 'id')->all();
    }
}
