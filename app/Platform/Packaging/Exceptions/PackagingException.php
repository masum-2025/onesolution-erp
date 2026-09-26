<?php

namespace App\Platform\Packaging\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class PackagingException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'packaging.errors.'.$this->errorCode;
    }

    /**
     * @param  list<array{key: string, name: string, max: int|null}>  $upgrade  Plans that would allow more.
     */
    public static function limitReached(string $limit, int $max, int $used, string $plan, array $upgrade): self
    {
        $suggestion = $upgrade === [] ? '' : ' '.__('packaging.errors.upgrade_to', [
            'plan' => $upgrade[0]['name'],
            'max' => $upgrade[0]['max'] === null ? __('packaging.unlimited') : (string) $upgrade[0]['max'],
        ]);

        return new self("{$limit}_limit_reached", 422, ['max' => (string) $max, 'plan' => $plan, 'upgrade' => $suggestion], [
            'limit' => $limit,
            'max' => $max,
            'used' => $used,
            'upgrade' => $upgrade,
        ]);
    }

    public static function unknownPlan(): self
    {
        return new self('unknown_plan', 422);
    }

    public static function samePlan(): self
    {
        return new self('same_plan', 422);
    }

    public static function noPrice(string $currency, string $period): self
    {
        return new self('no_price', 422, ['currency' => $currency, 'period' => __("packaging.periods.{$period}")]);
    }

    public static function planInUse(): self
    {
        return new self('plan_in_use', 422);
    }

    public static function moduleNotInBase(string $module): self
    {
        return new self('module_not_in_base', 422, ['module' => $module]);
    }

    public static function moduleNeeds(string $module, string $required): self
    {
        return new self('module_needs', 422, ['module' => $module, 'required' => $required]);
    }

    public static function wrongAudience(string $audience): self
    {
        return new self("wrong_audience_{$audience}", 422, [], ['field' => 'plan']);
    }

    public static function notTopLevel(): self
    {
        return new self('not_top_level', 422);
    }

    public static function noPackage(): self
    {
        return new self('no_package', 422);
    }
}
