<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A job offer to an application: the salary (minor units), the first day
 * and the day to answer by. A draft until sent (or first approved by
 * someone else, when the rule asks); then accepted, declined or expired
 * by the candidate's answer, or withdrawn. The letter is kept as sent.
 */
#[Fillable(['organization_id', 'application_id', 'currency', 'salary_minor', 'start_on', 'respond_by', 'status', 'letter', 'locale', 'token_hash', 'created_by', 'approved_by', 'note', 'sent_at', 'answered_at', 'version'])]
class Offer extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const WITHDRAWN = 'withdrawn';

    /** An offer still going: the application has no other one. */
    public const LIVE = [self::DRAFT, self::PENDING, self::SENT, self::ACCEPTED];

    protected $table = 'rec_offers';

    protected $hidden = ['token_hash', 'letter'];

    protected function casts(): array
    {
        return [
            'salary_minor' => 'integer', 'start_on' => 'date', 'respond_by' => 'date', 'sent_at' => 'datetime', 'answered_at' => 'datetime',
            'letter' => 'encrypted', 'version' => 'integer',
        ];
    }
}
