<?php

namespace App\Platform\Connections\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one side sent the other over a connection: an envelope (who, what
 * kind, when, delivered or not) and, until it is delivered, the encrypted
 * payload and the files to copy. Nothing is mass assignable.
 */
#[Table('connection_messages')]
class ConnectionMessage extends Model
{
    use HasUlids;

    public const TO_CLIENT = 'to_client';

    public const TO_PROVIDER = 'to_provider';

    public const QUEUED = 'queued';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $guarded = ['*'];

    protected $hidden = ['payload', 'files'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'files' => 'array',
            'attempts' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * The business connection (not "connection": Eloquent's own database connection name).
     *
     * @return BelongsTo<Connection, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }
}
