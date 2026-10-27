<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A candidate for an opening: the stage it is at (the company's rule),
 * where it came from, a rating, and how it ended (hired into HRM,
 * rejected, withdrawn). From the careers page it is unconfirmed until the
 * person confirms their email.
 */
#[Fillable(['organization_id', 'opening_id', 'unit_id', 'candidate_id', 'stage', 'status', 'source', 'cover_note', 'rating', 'reject_reason', 'decided_at', 'employee_id', 'confirm_token_hash', 'confirm_expires_at', 'confirmed_at', 'op_id', 'version'])]
class Application extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UNCONFIRMED = 'unconfirmed';

    public const ACTIVE = 'active';

    public const HIRED = 'hired';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::UNCONFIRMED, self::ACTIVE, self::HIRED, self::REJECTED, self::WITHDRAWN];

    public const SOURCES = ['careers', 'manual', 'referral'];

    protected $table = 'rec_applications';

    protected $hidden = ['cover_note', 'confirm_token_hash'];

    protected function casts(): array
    {
        return [
            'cover_note' => 'encrypted', 'rating' => 'integer', 'decided_at' => 'datetime', 'confirm_expires_at' => 'datetime',
            'confirmed_at' => 'datetime', 'version' => 'integer',
        ];
    }
}
