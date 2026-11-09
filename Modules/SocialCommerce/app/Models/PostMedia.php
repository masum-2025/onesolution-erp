<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A photo of a post: a private file, shown through a short-lived signed link. */
#[Fillable(['organization_id', 'post_id', 'position', 'path', 'mime', 'bytes', 'width', 'height'])]
class PostMedia extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'social_post_media';

    protected function casts(): array
    {
        return ['position' => 'integer', 'bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
