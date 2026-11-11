<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/**
 * A discharge summary: diagnosis, treatment, advice, follow-up and outcome.
 * A draft (with its version) until the patient is discharged, then signed and
 * never changed; an amendment is the next revision, the earlier one kept and
 * marked superseded.
 */
#[Fillable(['organization_id', 'admission_id', 'revision', 'diagnosis', 'treatment', 'advice', 'follow_up_on', 'outcome', 'amend_reason', 'written_by'])]
class Discharge extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const SIGNED = 'signed';

    public const OUTCOMES = ['cured', 'improved', 'referred', 'lama', 'death'];

    public const TEXTS = ['diagnosis', 'treatment', 'advice', 'follow_up_on', 'outcome'];

    protected $table = 'inpatient_discharges';

    protected $attributes = ['version' => 1, 'status' => self::DRAFT, 'revision' => 1];

    protected function casts(): array
    {
        return ['follow_up_on' => 'date:Y-m-d', 'signed_at' => 'datetime', 'superseded_at' => 'datetime', 'revision' => 'integer', 'version' => 'integer'];
    }
}
