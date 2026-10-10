<?php

namespace Modules\EducationFees\Services;

/** What a run reads once for all its students. */
final class BillingContext
{
    /**
     * @param  list<array<string, mixed>>  $structures
     * @param  array<string, mixed>  $concessions  By student id.
     * @param  array<string, int>  $places  Sibling places by student id.
     * @param  array<string, int>  $taxRates  By tax code id.
     */
    public function __construct(
        public array $structures,
        public array $concessions,
        public array $places,
        public array $taxRates,
        public ?int $month,
    ) {}
}
