<?php

namespace App\Platform\Tenancy\Exceptions;

class HierarchyViolation extends TenancyException
{
    public static function invalidParent(string $type, string $parentType): self
    {
        return new self('invalid_parent', 422, [
            'type' => __('tenancy.types.'.$type),
            'parent' => __('tenancy.types.'.$parentType),
        ]);
    }

    public static function cycle(): self
    {
        return new self('move_cycle', 422);
    }

    public static function crossPartner(): self
    {
        return new self('move_cross_partner', 422);
    }

    public static function tooDeep(int $maxDepth): self
    {
        return new self('max_depth', 422, ['max' => (string) $maxDepth]);
    }

    public static function alreadyThere(): self
    {
        return new self('move_same_parent', 422);
    }
}
