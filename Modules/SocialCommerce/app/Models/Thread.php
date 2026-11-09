<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A conversation with one customer: their comments under one post, or their Messenger chat with the page. */
#[Fillable(['organization_id', 'page_id', 'kind', 'thread_key', 'customer_ref', 'customer_name', 'fb_post_id', 'status', 'assigned_to', 'unknown_count', 'last_in_at', 'last_out_at', 'version'])]
class Thread extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const COMMENT = 'comment';

    public const MESSAGE = 'message';

    public const OPEN = 'open';

    /** Waiting for a person to answer. */
    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const STATUSES = [self::OPEN, self::WAITING, self::DONE];

    protected $table = 'social_threads';

    protected function casts(): array
    {
        return [
            'unknown_count' => 'integer',
            'last_in_at' => 'immutable_datetime',
            'last_out_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
