<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of the controlled-drug register. Append-only: a mistake is put right by a new entry, never by changing or removing one.
 */
#[Fillable(['organization_id', 'unit_id', 'medicine_id', 'batch_number', 'direction', 'quantity_milli', 'balance_after_milli', 'source_type', 'source_id', 'patient_name', 'prescriber_bmdc', 'note', 'recorded_by', 'recorded_at'])]
class ControlledEntry extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_controlled_entries';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('The controlled-drug register is append-only.'));
        static::deleting(fn () => throw new \LogicException('The controlled-drug register is append-only.'));
    }

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer', 'balance_after_milli' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
