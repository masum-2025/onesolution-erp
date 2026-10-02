<?php

namespace Tests\Fixtures;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;

/** How a module adds a line to the header bell. Test-only. */
class FixtureExpiringDocuments implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        return [
            new AttentionItem('hrm.documents_expiring', 'Documents expiring soon', 3, '/hrm', 'bad'),
            new AttentionItem('hrm.nothing', 'Nothing here', 0, '/hrm'),
        ];
    }
}
