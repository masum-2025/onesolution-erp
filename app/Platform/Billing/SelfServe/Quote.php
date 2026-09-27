<?php

namespace App\Platform\Billing\SelfServe;

use Carbon\CarbonImmutable;

/**
 * What buying a plan for one period costs now: integer minor units, tax at
 * the account's rate (half up). The same numbers go on the payment and on
 * the invoice issued when it succeeds.
 */
final readonly class Quote
{
    public function __construct(
        public string $planKey,
        public string $period,
        public string $currency,
        public int $subtotalMinor,
        public int $taxRateBp,
        public int $taxMinor,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function totalMinor(): int
    {
        return $this->subtotalMinor + $this->taxMinor;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'period' => $this->period,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotalMinor,
            'tax_rate_bp' => $this->taxRateBp,
            'tax_minor' => $this->taxMinor,
            'total_minor' => $this->totalMinor(),
            'starts_on' => $this->start->toDateString(),
            'ends_on' => $this->end->toDateString(),
        ];
    }
}
