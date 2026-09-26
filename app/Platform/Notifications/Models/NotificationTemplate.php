<?php

namespace App\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A partner's own wording for one notification, channel and language.
 * Plain text with {{ placeholders }} only; no markup, no logic.
 */
#[Table('notification_templates')]
class NotificationTemplate extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
