<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A check-in or check-out as it happened (UTC): by the person, by HR, from
 * a correction, an attendance machine or a phone that was offline. Where it
 * was made is kept only when the unit asks for a location check. Never
 * changed: a mistaken one is voided with a reason and stays for the record.
 */
#[Fillable([
    'organization_id', 'unit_id', 'employee_id', 'punched_at', 'source', 'op_id', 'note', 'created_by',
    'latitude_micro', 'longitude_micro', 'accuracy_m', 'distance_m', 'location_id',
])]
class Punch extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SOURCES = ['self', 'manual', 'correction', 'device', 'offline'];

    protected $table = 'att_punches';

    /** What may still change: the voiding, and forgetting where it was made (rule attendance.location_retention_days). */
    private const WRITABLE = ['voided_at', 'voided_by', 'void_reason', 'latitude_micro', 'longitude_micro', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Punch $punch) {
            if (array_diff(array_keys($punch->getDirty()), self::WRITABLE) !== []) {
                throw new LogicException('Punches never change; void them instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Punches are never deleted; void them instead.'));
    }

    protected function casts(): array
    {
        return [
            'punched_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime', 'latitude_micro' => 'integer',
            'longitude_micro' => 'integer', 'accuracy_m' => 'integer', 'distance_m' => 'integer',
        ];
    }
}
