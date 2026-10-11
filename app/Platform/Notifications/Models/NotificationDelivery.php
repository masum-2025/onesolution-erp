<?php

namespace App\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One message to one person on one channel. The address is stored masked;
 * the placeholder values are kept only until the message is sent. A person
 * without a login (a guardian's phone on a student's record) has no user:
 * their address is kept encrypted until then too.
 */
#[Table('notification_deliveries')]
class NotificationDelivery extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'address' => 'encrypted',
            'sent_at' => 'datetime',
        ];
    }
}
