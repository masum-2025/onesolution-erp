<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A Facebook post written in the ERP and where it stands. */
#[Fillable(['organization_id', 'page_id', 'message', 'locale', 'status', 'publish_at', 'created_by', 'op_id', 'version'])]
class Post extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const STATUSES = [self::DRAFT, self::PENDING, self::SCHEDULED, self::PUBLISHING, self::PUBLISHED, self::REJECTED, self::FAILED];

    /** Statuses in which the post may still be changed by its writers. */
    public const EDITABLE = [self::DRAFT, self::REJECTED, self::FAILED];

    protected $table = 'social_posts';

    protected function casts(): array
    {
        return [
            'publish_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'version' => 'integer',
        ];
    }

    public function editable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }
}
