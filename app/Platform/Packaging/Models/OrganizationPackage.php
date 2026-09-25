<?php

namespace App\Platform\Packaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sector package applied to one organization, with what it did. Written
 * only by ApplySectorPackage; read through visible-organization lookups.
 */
#[Table('organization_packages')]
#[Fillable(['organization_id', 'package_key', 'package_version', 'applied_by', 'summary'])]
class OrganizationPackage extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }
}
