<?php

namespace App\Platform\Packaging\Actions;

use RuntimeException;

/**
 * Carries a plan-change preview out of the transaction that is rolled back.
 *
 * @internal
 */
final class PreviewResult extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public readonly array $summary)
    {
        parent::__construct('preview');
    }
}
