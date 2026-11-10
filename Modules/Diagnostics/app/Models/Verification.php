<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A signature on one amendment of a test's results (append-only).
 */
#[Fillable(['organization_id', 'order_test_id', 'amendment', 'verified_by', 'verified_at', 'note'])]
class Verification extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'diagnostics_verifications';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verifications are append-only.'));
        static::deleting(fn () => throw new LogicException('Verifications are append-only.'));
    }

    protected function casts(): array
    {
        return ['amendment' => 'integer', 'verified_at' => 'immutable_datetime'];
    }
}
