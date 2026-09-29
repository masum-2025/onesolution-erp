<?php

namespace App\Platform\Offline\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An operation a device sent, kept once per (device, op_id) with its
 * result: sending it again returns the same result, never applies twice.
 * Nothing is mass assignable; rows are never updated.
 *
 * Not tenant-scoped on purpose (written by the sync pipeline, which checks
 * the device first; read by the device's own sync only).
 */
#[Table('sync_operations')]
class SyncOperationRecord extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'made_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
