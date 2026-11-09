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

    /** Words that only matter while an order is being taken in Messenger (SC-3): yes, and stop. */
    public const ORDER_WORDS = ['confirm', 'cancel'];

    /**
     * Answers with no words of their own: the public line when the price went to the inbox,
     * "a person will answer", the questions and lines of taking an order (SC-3), and the
     * customer's order news.
     */
    public const FIXED = [
        'notice', 'handoff',
        'ask_product', 'ask_quantity', 'ask_name', 'ask_phone', 'ask_address', 'ask_zone', 'bad_phone', 'short_stock', 'summary', 'placed', 'cancelled',
        'status_confirmed', 'status_shipped', 'status_delivered', 'status_cancelled',
    ];

    public const INTENTS = [...self::DETECTED, ...self::ORDER_WORDS, ...self::FIXED];

    protected $table = 'social_reply_templates';

    protected function casts(): array
    {
        return ['keywords' => 'array', 'answers' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }
}
