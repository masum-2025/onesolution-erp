<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/**
 * A bed of a ward: its code, the billing service of its daily charge, and its
 * state (free, occupied, cleaning, blocked) with a version, so two admissions
 * never take the same bed. An occupied bed names its admission.
 */
#[Fillable(['organization_id', 'unit_id', 'ward_id', 'code', 'service_code', 'is_active'])]
class Bed extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const FREE = 'free';

    public const OCCUPIED = 'occupied';

    public const CLEANING = 'cleaning';

    public const BLOCKED = 'blocked';

    public const STATUSES = [self::FREE, self::OCCUPIED, self::CLEANING, self::BLOCKED];

    protected $table = 'inpatient_beds';

    protected $attributes = ['version' => 1, 'status' => self::FREE];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
