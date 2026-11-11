<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's serial for a doctor's session on a date. Its number is kept for
 * good. Booked -> arrived -> in -> done; booked or arrived may become absent
 * or cancelled. The fee is a line on the patient's bill (none when free).
 */
#[Fillable(['organization_id', 'unit_id', 'doctor_id', 'chamber_id', 'schedule_id', 'date', 'serial_no', 'patient_id', 'kind', 'is_free', 'booked_by', 'op_id'])]
class Serial extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const BOOKED = 'booked';

    public const ARRIVED = 'arrived';

    public const IN = 'in';

    public const DONE = 'done';

    public const ABSENT = 'absent';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::BOOKED, self::ARRIVED, self::IN, self::DONE, self::ABSENT, self::CANCELLED];

    /** Where each status may go next. */
    public const NEXT = [
        self::BOOKED => [self::ARRIVED, self::ABSENT, self::CANCELLED],
        self::ARRIVED => [self::IN, self::ABSENT, self::CANCELLED],
        self::IN => [self::DONE, self::ARRIVED],
        self::DONE => [],
        self::ABSENT => [self::ARRIVED],
        self::CANCELLED => [],
    ];

    /** Statuses that hold a place in the session. */
    public const HOLDING = [self::BOOKED, self::ARRIVED, self::IN, self::DONE, self::ABSENT];

    public const NEW = 'new';

    public const FOLLOW_UP = 'follow_up';

    protected $table = 'outpatient_serials';

    protected $attributes = ['version' => 1, 'status' => self::BOOKED];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'serial_no' => 'integer', 'is_free' => 'boolean', 'version' => 'integer',
            'arrived_at' => 'datetime', 'called_at' => 'datetime', 'finished_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
