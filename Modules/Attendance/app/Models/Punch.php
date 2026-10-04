<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A check-in or check-out as it happened (UTC). Never changed: a mistaken
 * one is voided with a reason and stays for the record.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'punched_at', 'source', 'op_id', 'note', 'created_by'])]
class Punch extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SOURCES = ['self', 'manual', 'correction'];

    protected $table = 'att_punches';

    /** What may still change: only the voiding. */
    private const WRITABLE = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

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
        return ['punched_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }
}
