<?php

namespace App\Platform\Attention\Contracts;

use App\Platform\Attention\AttentionItem;
use App\Platform\Tenancy\Context\CurrentContext;

/**
 * Work waiting for the signed-in person in the current organization, counted
 * in the header bell. A provider checks its own permissions and returns
 * nothing the person may not open. Modules list theirs in the manifest
 * (`attention`); they are asked only while the module is on.
 */
interface AttentionProvider
{
    /**
     * @return list<AttentionItem>
     */
    public function items(CurrentContext $context): array;
}
