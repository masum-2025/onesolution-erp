<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A medicine on a prescription: its name as written (and the pharmacy's
 * medicine when picked from the list), dose ("1+0+1"), how long, when to take
 * it (printed in the prescription's language) and a short note.
 */
#[Fillable(['organization_id', 'consultation_id', 'position', 'medicine_id', 'medicine_text', 'dose', 'duration', 'timing', 'note'])]
class PrescriptionItem extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** When to take it; the words are translations, so a prescription prints in Bangla, English or both. */
    public const TIMINGS = ['before_meal', 'after_meal', 'with_meal', 'empty_stomach', 'bedtime', 'as_needed'];

    protected $table = 'outpatient_prescription_items';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
