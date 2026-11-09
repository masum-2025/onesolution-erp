<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One message in or out of a conversation (append-only). */
#[Fillable(['organization_id', 'thread_id', 'direction', 'sender', 'sender_user_id', 'channel', 'text', 'fb_id', 'intent', 'locale', 'understood_by', 'item_ids', 'status', 'error_code', 'op_id'])]
class Message extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    public const IN = 'in';

    public const OUT = 'out';

    public const CUSTOMER = 'customer';

    public const BOT = 'bot';

    public const PERSON = 'person';

    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    protected $table = 'social_messages';

    protected function casts(): array
    {
        return ['item_ids' => 'array'];
    }
}
