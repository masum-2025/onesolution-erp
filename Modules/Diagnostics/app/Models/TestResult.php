<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One parameter's value in one submission (amendment) of a test's results,
 * with its flag (low, high, critical_low, critical_high, abnormal).
 * Append-only: a correction is a new amendment, the old values stay.
 */
#[Fillable(['organization_id', 'order_test_id', 'parameter_id', 'amendment', 'value', 'flag', 'entered_by', 'entered_at'])]
class TestResult extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'diagnostics_results';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lab results are append-only.'));
        static::deleting(fn () => throw new LogicException('Lab results are append-only.'));
    }

    protected function casts(): array
    {
        return ['amendment' => 'integer', 'entered_at' => 'immutable_datetime'];
    }
}
