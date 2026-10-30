<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A renewal request for one register line, to its provider. Open while
 * sent, kept as a record (provider not on the platform) or accepted;
 * closed when declined, cancelled or done.
 */
#[Fillable(['organization_id', 'company_id', 'audit_id', 'provider_id', 'connection_id', 'message_id', 'status', 'wanted_by', 'note', 'planned_on', 'reply_note', 'replied_at', 'last_reply_id', 'requested_by', 'cancel_reason', 'version'])]
class RenewalRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SENT = 'sent';

    /** The provider is not on the platform: the client sends it by email or phone itself. */
    public const RECORDED = 'recorded';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const DONE = 'done';

    public const OPEN = [self::SENT, self::RECORDED, self::ACCEPTED];

    protected $table = 'cmp_requests';

    protected function casts(): array
    {
        return [
            'wanted_by' => 'immutable_date',
            'planned_on' => 'immutable_date',
            'replied_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
