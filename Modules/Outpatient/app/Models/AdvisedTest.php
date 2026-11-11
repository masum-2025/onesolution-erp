<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A test the doctor advised: as written, and the lab's test code when picked from its list. */
#[Fillable(['organization_id', 'consultation_id', 'position', 'test_code', 'test_text'])]
class AdvisedTest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'outpatient_advised_tests';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
