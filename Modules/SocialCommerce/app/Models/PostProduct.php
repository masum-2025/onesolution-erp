<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A product a post shows, with its price when the post was written. */
#[Fillable(['organization_id', 'post_id', 'item_id', 'price_minor', 'currency_code'])]
class PostProduct extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'social_post_products';

    protected function casts(): array
    {
        return ['price_minor' => 'integer'];
    }
}
