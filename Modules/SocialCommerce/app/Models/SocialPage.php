<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A company's connected Facebook page. The token is encrypted and never serialized. */
#[Fillable(['organization_id', 'meta_app_id', 'page_id', 'name', 'picture_url', 'token', 'meta_person_id', 'can_post', 'scopes', 'subscribed_at', 'auto_reply', 'price_public', 'order_branch_id', 'order_warehouse_id', 'status', 'status_reason', 'connected_by', 'connected_at', 'version'])]
class SocialPage extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const CONNECTED = 'connected';

    public const NEEDS_RECONNECT = 'needs_reconnect';

    public const DISCONNECTED = 'disconnected';

    protected $table = 'social_pages';

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'can_post' => 'boolean',
            'scopes' => 'array',
            'subscribed_at' => 'immutable_datetime',
            'auto_reply' => 'boolean',
            'price_public' => 'boolean',
            'connected_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function usable(): bool
    {
        return $this->status === self::CONNECTED && $this->can_post && $this->token !== null;
    }
}
