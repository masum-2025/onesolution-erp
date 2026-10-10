<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An issued document was revoked (it no longer verifies as valid). Ids only.
 */
class DocumentRevoked
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $documentId, public string $studentId) {}
}
