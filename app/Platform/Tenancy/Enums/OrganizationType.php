<?php

namespace App\Platform\Tenancy\Enums;

enum OrganizationType: string
{
    case Group = 'group';
    case Company = 'company';
    case Branch = 'branch';
    case Department = 'department';
}
