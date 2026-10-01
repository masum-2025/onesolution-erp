<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file kept for an employee (contract, certificate, id copy). Stored on the
 * private disk; read only through a short-lived signed link (audited).
 */
#[Fillable(['organization_id', 'employee_id', 'type', 'title', 'file_path', 'mime', 'size_bytes', 'expires_on', 'uploaded_by'])]
class EmployeeDocument extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_documents';

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
