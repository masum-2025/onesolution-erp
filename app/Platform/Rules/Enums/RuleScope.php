<?php

namespace App\Platform\Rules\Enums;

use App\Platform\Tenancy\Enums\OrganizationType;

/**
 * Levels a rule value can be stored at, from the most general to the most
 * specific. Resolution walks them in this order.
 */
enum RuleScope: string
{
    case Platform = 'platform';
    case Partner = 'partner';
    case Plan = 'plan';
    case Group = 'group';
    case Company = 'company';
    case Branch = 'branch';
    case Department = 'department';
    case Role = 'role';
    case User = 'user';

    public static function forOrganizationType(OrganizationType $type): self
    {
        return self::from($type->value);
    }

    public function isOrganizationLevel(): bool
    {
        return in_array($this, [self::Group, self::Company, self::Branch, self::Department], true);
    }
}
