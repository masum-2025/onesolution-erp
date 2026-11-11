<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A link that opens one order's verified report without signing in, until
 * it expires. Only the SHA-256 of the token is kept; the token itself is
 * shown once (and sent by SMS when asked).
 */
#[Fillable(['organization_id', 'order_id', 'token_hash', 'expires_at', 'created_by', 'sent_by_sms'])]
class ReportLink extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'diagnostics_report_links';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_opened_at' => 'immutable_datetime', 'sent_by_sms' => 'boolean', 'opened_count' => 'integer'];
    }
}
