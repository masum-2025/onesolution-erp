<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student of the institution, at a campus, following a program.
 *
 * The birth registration number is encrypted at rest; birth_registration_hash
 * (keyed) finds a duplicate without reading it. Date of birth and the number
 * are shown only with education.view_sensitive. A student is never deleted:
 * they leave or graduate, and their enrollments keep the history.
 */
#[Fillable([
    'organization_id', 'unit_id', 'code', 'admission_no', 'name', 'name_local', 'gender', 'date_of_birth',
    'birth_registration_no', 'birth_registration_hash', 'phone', 'email', 'photo_path', 'program_id', 'batch_id',
    'category_id', 'status', 'admitted_on', 'left_on', 'left_reason', 'user_id', 'extra', 'created_by', 'op_id', 'version',
])]
class Student extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['active', 'suspended', 'left', 'graduated'];

    protected $table = 'edu_students';

    /** Never in API output by accident: presenters choose what to show. */
    protected $hidden = ['birth_registration_no', 'birth_registration_hash', 'photo_path'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'birth_registration_no' => 'encrypted',
            'admitted_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'extra' => 'array',
            'version' => 'integer',
        ];
    }

    /** Keyed hash of a registration number (same number, same hash; useless without the app key). */
    public static function hashOf(?string $number): ?string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', (string) $number);

        return $normalized === '' ? null : hash_hmac('sha256', strtoupper($normalized), (string) config('app.key'));
    }
}
