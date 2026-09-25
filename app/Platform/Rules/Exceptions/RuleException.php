<?php

namespace App\Platform\Rules\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class RuleException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'rules.errors.'.$this->errorCode;
    }

    public static function unknownRule(): self
    {
        return new self('unknown_rule', 404);
    }

    public static function levelNotAllowed(string $rule, string $level): self
    {
        return new self('level_not_allowed', 422, ['rule' => $rule, 'level' => __('rules.levels.'.$level)]);
    }

    public static function invalidValue(string $rule, string $detail): self
    {
        return new self('invalid_value', 422, ['rule' => $rule], ['detail' => $detail]);
    }

    public static function invalidBounds(string $rule, string $detail): self
    {
        return new self('invalid_bounds', 422, ['rule' => $rule], ['detail' => $detail]);
    }

    public static function lockedByParent(string $rule, string $by): self
    {
        return new self('locked_by_parent', 422, ['rule' => $rule, 'by' => $by]);
    }

    /**
     * @param  array<string, mixed>  $bounds
     */
    public static function violatesConstraint(string $rule, string $by, array $bounds): self
    {
        return new self('violates_constraint', 422, ['rule' => $rule, 'by' => $by], ['constraints' => $bounds]);
    }

    public static function boundsWiderThanParent(string $rule, string $by): self
    {
        return new self('bounds_wider_than_parent', 422, ['rule' => $rule, 'by' => $by]);
    }

    public static function notCountrySpecific(string $rule): self
    {
        return new self('not_country_specific', 422, ['rule' => $rule]);
    }

    public static function moduleDisabled(string $rule): self
    {
        return new self('module_disabled', 422, ['rule' => $rule]);
    }

    public static function selfApproval(): self
    {
        return new self('self_approval', 422);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 422);
    }

    public static function valueNotFound(): self
    {
        return new self('value_not_found', 404);
    }

    public static function nothingToReset(): self
    {
        return new self('nothing_to_reset', 404);
    }
}
