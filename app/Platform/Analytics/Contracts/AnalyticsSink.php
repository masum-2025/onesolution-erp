<?php

namespace App\Platform\Analytics\Contracts;

/**
 * Where analytics rows go (Phase 10-3). A sink must accept a batch fully or
 * throw; the cursor only moves on after it returns.
 */
interface AnalyticsSink
{
    public function name(): string;

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function send(string $dataset, array $rows): void;
}
