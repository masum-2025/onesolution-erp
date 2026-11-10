<?php

namespace Modules\Pharmacy\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The pharmacy's rules at a unit (a branch may differ from its company where
 * the rule allows), and today's date in the company's time zone.
 */
class PharmacyRules
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function get(string $name, Organization $unit): mixed
    {
        return $this->rules->get("pharmacy.{$name}", $this->contexts->forOrganization($unit));
    }

    public function flag(string $name, Organization $unit): bool
    {
        return (bool) $this->get($name, $unit);
    }

    public function days(string $name, Organization $unit): int
    {
        return max(0, (int) $this->get($name, $unit));
    }

    /** Today where the company is (a batch expiring today is still sellable today). */
    public function today(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfDay();
    }
}
