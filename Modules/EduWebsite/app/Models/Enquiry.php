<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An admission enquiry from the public website: who asks (encrypted), the
 * class asked for, a message, and how the office handles it (new,
 * contacted, admitted, closed) with its own note.
 */
#[Fillable(['organization_id', 'site_id', 'student_name', 'guardian_name', 'phone', 'phone_hash', 'level_id', 'level_name', 'message', 'locale', 'status', 'note', 'version'])]
class Enquiry extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['new', 'contacted', 'admitted', 'closed'];

    protected $table = 'web_enquiries';

    /** Never in arrays or JSON by accident (the presenter picks what to show). */
    protected $hidden = ['student_name', 'guardian_name', 'phone', 'phone_hash', 'message', 'note'];

    protected function casts(): array
    {
        return [
            'student_name' => 'encrypted', 'guardian_name' => 'encrypted', 'phone' => 'encrypted', 'message' => 'encrypted', 'note' => 'encrypted',
            'level_name' => 'array', 'version' => 'integer',
        ];
    }
}
