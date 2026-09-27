<?php

namespace App\Platform\Payments\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A message a gateway sent us, kept once per gateway reference: a repeated
 * notification finds its earlier record and is not applied again. The
 * payload never holds secrets or card details. Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: it arrives before anyone is known and is
 * read only by the platform.
 */
#[Table('gateway_events')]
class GatewayEvent extends Model
{
    use HasUlids;

    public const NOTIFICATION = 'notification';

    public const RETURN = 'return';

    public const LOOKUP = 'lookup';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
