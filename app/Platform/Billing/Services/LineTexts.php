<?php

namespace App\Platform\Billing\Services;

use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\PlanCatalog;
use Carbon\CarbonImmutable;

/**
 * Invoice line texts, frozen in every supported language when a document is
 * issued (the document must read the same years later).
 */
class LineTexts
{
    public function __construct(private PlanCatalog $plans) {}

    /**
     * @param  callable(string $locale): array<string, string>  $replace
     * @return array<string, string>
     */
    public function make(string $key, callable $replace): array
    {
        $texts = [];
        foreach ((array) config('tenancy.supported_locales') as $locale) {
            $texts[$locale] = __("billing.lines.{$key}", $replace($locale), $locale);
        }

        return $texts;
    }

    public function planName(string $planKey, ?PartnerPlan $partnerPlan, string $locale): string
    {
        return $partnerPlan?->label($locale) ?? $this->plans->get($planKey)->label($locale);
    }

    public function month(CarbonImmutable $month, string $locale): string
    {
        return $month->locale($locale)->isoFormat('MMMM YYYY');
    }

    public function day(CarbonImmutable $day, string $locale): string
    {
        return $day->locale($locale)->isoFormat('D MMM YYYY');
    }

    /**
     * @param  array<string, string>|string|null  $name
     */
    public function name(array|string|null $name, string $locale): string
    {
        return is_array($name) ? (string) ($name[$locale] ?? $name['en'] ?? reset($name)) : (string) $name;
    }
}
