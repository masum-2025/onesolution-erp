<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A CV or other paper of an application, on the private disk; shown through short signed links only. */
#[Fillable(['organization_id', 'application_id', 'name', 'mime', 'size_bytes', 'path', 'uploaded_by'])]
class CandidateFile extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'rec_files';

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
