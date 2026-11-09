<?php

namespace App\Platform\Ai;

/** What a model read: the answer in the shape asked for, and the tokens it cost. */
final class AiReading
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}
}
