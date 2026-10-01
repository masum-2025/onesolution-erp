<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Hrm\Enums\EmployeeStatus;

/**
 * A person employed by a company, working in one of its units. Never deleted:
 * employment ends with an exit (HR and pay records must stay). Status and
 * job changes go through EmployeeLifecycle so each leaves an employment event.
 *
 * National and tax ids are encrypted at rest; national_id_hash (keyed) finds
 * duplicates without decrypting.
 */
#[Fillable([
    'organization_id', 'full_name', 'full_name_local', 'date_of_birth', 'gender', 'phone', 'email',
    'address', 'emergency_contact', 'national_id', 'tax_id', 'employment_type', 'position_id', 'manager_id',
])]
class Employee extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_employees';

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'joined_on' => 'immutable_date',
            'probation_ends_on' => 'immutable_date',
            'confirmed_on' => 'immutable_date',
            'notice_given_on' => 'immutable_date',
            'exits_on' => 'immutable_date',
            'address' => 'array',
            'emergency_contact' => 'array',
            'national_id' => 'encrypted',
            'tax_id' => 'encrypted',
            'status' => EmployeeStatus::class,
            'version' => 'integer',
        ];
    }

    /** Keyed hash of a national id (same id, same hash; useless without the app key). */
    public static function hashOf(?string $nationalId): ?string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', (string) $nationalId);

        return $normalized === '' ? null : hash_hmac('sha256', strtoupper($normalized), (string) config('app.key'));
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * @return HasMany<EmploymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmploymentEvent::class);
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
