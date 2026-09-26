<?php

namespace App\Platform\Packaging\Actions;

use App\Platform\Packaging\Models\PartnerPlan;

/**
 * What a subscription moves to: one of our plans, or a partner plan on top
 * of one, and optionally new billing terms. Terms left null stay as they are.
 */
final readonly class PlanChoice
{
    public string $planKey;

    public function __construct(
        string $planKey,
        public ?PartnerPlan $partnerPlan = null,
        public ?string $currency = null,
        public ?string $period = null,
    ) {
        // A partner plan always sits on its own base plan.
        $this->planKey = $partnerPlan?->base_plan_key ?? $planKey;
    }

    public static function of(string|self $choice): self
    {
        return $choice instanceof self ? $choice : new self($choice);
    }
}
