<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What the doctor wrote for a serial. A draft is saved with its version; a
 * finished one is never changed: an amendment is the next revision (with a
 * reason), and the one it replaces gets superseded_at when it is finished.
 */
#[Fillable(['organization_id', 'unit_id', 'serial_id', 'patient_id', 'doctor_id', 'revision', 'complaints', 'findings', 'diagnosis', 'advice', 'follow_up_on', 'amend_reason', 'written_by'])]
class Consultation extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const FINISHED = 'finished';

    /** The findings a doctor may note (all optional, short text). */
    public const FINDINGS = ['bp', 'pulse', 'temperature', 'weight', 'spo2', 'other'];

    protected $table = 'outpatient_consultations';

    protected $attributes = ['version' => 1, 'status' => self::DRAFT, 'revision' => 1];

    protected function casts(): array
    {
        return ['findings' => 'array', 'follow_up_on' => 'date:Y-m-d', 'finished_at' => 'datetime', 'superseded_at' => 'datetime', 'revision' => 'integer', 'version' => 'integer'];
    }
}
