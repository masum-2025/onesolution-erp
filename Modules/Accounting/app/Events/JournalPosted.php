<?php

namespace Modules\Accounting\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A journal entered the books (manifest event "accounting.journal.posted").
 * The module that sent it (source) learns its entry is final. Ids only.
 */
class JournalPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $journalId,
        public string $organizationId,
        public string $entryDate,
        public ?string $sourceModule = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {}
}
