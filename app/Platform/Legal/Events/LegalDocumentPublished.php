<?php

namespace App\Platform\Legal\Events;

use App\Platform\Legal\Models\LegalDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new version of a legal document was published (by a partner, or the
 * platform's default). After commit.
 */
class LegalDocumentPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public LegalDocument $document) {}
}
