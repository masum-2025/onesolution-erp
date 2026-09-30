<?php

namespace App\Platform\Tenancy\Databases;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One move of a client tree's business data to another database, with its
 * verification report. Written only by TenantMover.
 *
 * @property string $status
 * @property array<string, mixed>|null $report
 */
#[Fillable([
    'root_organization_id', 'from_database', 'to_database', 'status', 'reason',
    'actor_user_id', 'report', 'started_at', 'finished_at',
])]
class TenantMove extends Model
{
    use HasUlids;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
