<?php

namespace Modules\Letters\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A letter issued to one employee: its number, language and content with
 * every placeholder filled (a copy that never changes); revoked with a
 * reason, never deleted.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'employee_code', 'employee_name', 'template_id', 'template_version', 'kind', 'number',
    'locale', 'title', 'content', 'uses_salary', 'shared', 'issued_by', 'issued_at', 'op_id'])]
class Letter extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'let_letters';

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'uses_salary' => 'boolean',
            'shared' => 'boolean',
            'template_version' => 'integer',
            'issued_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
