<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Hrm\Enums\ImportRowStatus;

/**
 * One line of an import. data holds the hire details (national ids among
 * them), so it is encrypted and wiped when the import ends; errors name
 * columns and problems, never the values.
 */
#[Fillable(['organization_id', 'import_id', 'row_no', 'data', 'errors', 'status', 'employee_id'])]
class ImportRow extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_import_rows';

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'errors' => 'array',
            'status' => ImportRowStatus::class,
            'row_no' => 'integer',
        ];
    }
}
