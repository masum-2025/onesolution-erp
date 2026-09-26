<?php

namespace App\Platform\Billing\Services;

/**
 * What a billing run did: documents issued, documents that already existed,
 * and what could not be billed (with a reason someone can act on).
 */
final class BillingRunResult
{
    /** @var list<string> */
    public array $issued = [];

    public int $already = 0;

    /** @var list<array{partner: string, reason: string}> */
    public array $skipped = [];

    public function skip(string $partner, string $reason): void
    {
        $this->skipped[] = ['partner' => $partner, 'reason' => $reason];
    }
}
