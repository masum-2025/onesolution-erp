<?php

namespace App\Platform\Tenancy\Enums;

enum OrganizationType: string
{
    case Group = 'group';
    case Company = 'company';
    case Branch = 'branch';
    case Department = 'department';
    // A self-serve individual's workspace (Phase 5C): always at the top, one
    // owner, nothing below it. It works like a company for rules and modules,
    // so upgrading to a company later only changes this type.
    case Personal = 'personal';

    /**
     * Works as a company: the level whose rules, modules and sector apply.
     */
    public function isCompanyLike(): bool
    {
        return $this === self::Company || $this === self::Personal;
    }

    /**
     * @return list<self>
     */
    public static function companyLike(): array
    {
        return [self::Company, self::Personal];
    }
}
