<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient of a company (registered at one of its branches), known by the
 * patient number (MRN) everywhere in the company. The ID number and allergies
 * are encrypted at rest; the ID number is also kept as a keyed hash so a
 * duplicate can be found without decrypting. A merged patient keeps its row
 * and points to the record that replaced it.
 */
#[Fillable([
    'organization_id', 'unit_id', 'mrn', 'name', 'name_local', 'sex', 'date_of_birth', 'dob_estimated', 'mobile',
    'id_type', 'id_number', 'id_number_hash', 'guardian_name', 'guardian_relation', 'blood_group', 'allergies', 'address',
    'created_by', 'op_id', 'version',
])]
class Patient extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SEXES = ['male', 'female', 'other'];

    public const ID_TYPES = ['nid', 'birth_reg', 'passport', 'none'];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    protected $table = 'patients_patients';

    protected $attributes = ['version' => 1, 'id_type' => 'none', 'dob_estimated' => false];

    protected $hidden = ['id_number', 'id_number_hash', 'allergies'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'date_of_birth' => 'immutable_date',
            'dob_estimated' => 'boolean',
            'id_number' => 'encrypted',
            'allergies' => 'encrypted',
        ];
    }

    /** A keyed hash of an ID number (letters and digits only, upper case), or null. */
    public static function hashOf(?string $idNumber): ?string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', (string) $idNumber);

        return $normalized === '' ? null : hash_hmac('sha256', strtoupper($normalized), (string) config('app.key'));
    }

    /** Whole years on a day, or null without a birth date. */
    public function ageOn(CarbonImmutable $day): ?int
    {
        if ($this->date_of_birth === null) {
            return null;
        }
        // Calendar dates only: the time zone of either side must not shift a birthday.
        [$born, $on] = [$this->date_of_birth, $day];
        $years = $on->year - $born->year - (($on->month * 100 + $on->day) < ($born->month * 100 + $born->day) ? 1 : 0);

        return max(0, $years);
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }
}
