<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An ID card, certificate or letter was issued to a student (kind says which). Ids only.
 */
class DocumentIssued
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $documentId, public string $studentId, public string $kind) {}
}
