<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A session's group of one level at a campus (Class 6 - A, morning, Bangla medium), with its class teacher.
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'level_id', 'name', 'stream_id', 'shift_id', 'medium_id', 'capacity', 'class_teacher_id', 'is_active', 'version'])]
class Section extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_sections';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
