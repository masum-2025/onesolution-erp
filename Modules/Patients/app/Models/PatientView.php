<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One time a person opened a patient's record, history or card (append-only:
 * never changed or removed).
 */
#[Fillable(['organization_id', 'patient_id', 'user_id', 'purpose', 'viewed_at'])]
class PatientView extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PURPOSES = ['record', 'history', 'card', 'prescription'];

    public $timestamps = false;

    protected $table = 'patients_views';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Patient views are append-only.'));
        static::deleting(fn () => throw new LogicException('Patient views are append-only.'));
    }

    protected function casts(): array
    {
        return ['viewed_at' => 'immutable_datetime'];
    }
}
