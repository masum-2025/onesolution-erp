<?php

namespace App\Platform\Portal;

use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What a portal member may see, for modules: only the records of their
 * active links, of one kind. Modules call restrict() on every query that can
 * serve a portal member; for staff it changes nothing.
 *
 *   PortalAccess::restrict(Student::query(), 'school.student')->get();
 */
class PortalAccess
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private CurrentContext $context) {}

    public function isPortal(): bool
    {
        return $this->context->hasOrganization() && $this->context->membership()->membership_type === MembershipType::Portal;
    }

    /**
     * Ids of the records of this kind the acting portal member may see.
     *
     * @return list<string>
     */
    public function subjectIds(string $type): array
    {
        if (! $this->isPortal()) {
            return [];
        }

        $membership = $this->context->membership();

        return $this->memo[$membership->getKey().':'.$type] ??= PortalLink::query()
            ->where('membership_id', $membership->getKey())
            ->where('organization_id', $membership->organization_id)
            ->where('subject_type', $type)
            ->where('status', PortalLink::ACTIVE)
            ->pluck('subject_id')
            ->all();
    }

    public function canSee(string $type, string $id): bool
    {
        return in_array($id, $this->subjectIds($type), true);
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrict(Builder $query, string $type, string $column = 'id'): Builder
    {
        if ($this->isPortal()) {
            // No links: whereIn with an empty list matches nothing.
            $query->whereIn($column, $this->subjectIds($type));
        }

        return $query;
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
