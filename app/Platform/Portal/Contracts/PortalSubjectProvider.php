<?php

namespace App\Platform\Portal\Contracts;

use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Models\Organization;

/**
 * A kind of record a module lets a portal show (e.g. school.student,
 * hrm.employee, crm.customer). The module declares it in its manifest
 * ("portal_subjects") and answers here; the portal never reads the module's
 * tables. Every lookup is limited to the given organization and the units
 * below it, and works without a signed-in tenant context (joining).
 */
interface PortalSubjectProvider
{
    /** "{module}.{kind}", e.g. "school.student". */
    public function key(): string;

    /** The kind of record, for people: "Student". */
    public function label(?string $locale = null): string;

    /**
     * How a person can relate to such a record.
     *
     * @return list<string> e.g. ['guardian'] or ['self']
     */
    public function relations(): array;

    public function find(Organization $organization, string $id): ?PortalSubject;

    /**
     * Records matching a search, for staff picking whom to invite.
     *
     * @return list<PortalSubject>
     */
    public function search(Organization $organization, string $term, int $limit = 20): array;

    /**
     * What a portal member may see of the record, before the client's hidden
     * fields are taken out: field key => [label, value].
     *
     * @return array<string, array{label: string, value: string|int|float|bool|null}>
     */
    public function details(PortalSubject $subject, ?string $locale = null): array;
}
