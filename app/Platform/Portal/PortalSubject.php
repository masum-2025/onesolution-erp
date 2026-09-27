<?php

namespace App\Platform\Portal;

/**
 * One record a portal can show, as its module describes it.
 */
final readonly class PortalSubject
{
    /**
     * @param  string  $organizationId  The unit the record belongs to.
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $organizationId,
        public string $name,
    ) {}
}
