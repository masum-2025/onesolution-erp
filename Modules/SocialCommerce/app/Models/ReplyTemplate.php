<?php

namespace Modules\SocialCommerce\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What the bot understands for one question (words per language) and what it answers. */
#[Fillable(['organization_id', 'intent', 'keywords', 'answers', 'active', 'version'])]
class ReplyTemplate extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** Questions the bot recognises, in the order it tries them. */
    public const DETECTED = ['price', 'stock', 'delivery', 'order'];

    /** Answers with no words of their own: the public line when the price went to the inbox, and "a person will answer". */
    public const FIXED = ['notice', 'handoff'];

    public const INTENTS = [...self::DETECTED, ...self::FIXED];

    protected $table = 'social_reply_templates';

    protected function casts(): array
    {
        return ['keywords' => 'array', 'answers' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }
}
