<?php

namespace Modules\Letters\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The last letter number used per code and year at a company. */
#[Fillable(['organization_id', 'code', 'year', 'last'])]
class LetterSequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'let_sequences';

    protected function casts(): array
    {
        return ['year' => 'integer', 'last' => 'integer'];
    }
}
