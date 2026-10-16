<?php

namespace Modules\EducationLectures\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file of a lecture, kept on the private disk under the institution and
 * opened only through a short-lived signed link.
 */
#[Fillable(['organization_id', 'lecture_id', 'name', 'mime', 'size_bytes', 'path', 'checksum', 'created_by'])]
class LectureFile extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'lec_files';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
