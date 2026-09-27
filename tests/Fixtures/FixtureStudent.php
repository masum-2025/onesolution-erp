<?php

namespace Tests\Fixtures;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a school module's student record in portal tests.
 */
#[Fillable(['organization_id', 'name', 'class_name', 'date_of_birth'])]
class FixtureStudent extends Model
{
    use BelongsToOrganization, HasUlids;

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }
}
