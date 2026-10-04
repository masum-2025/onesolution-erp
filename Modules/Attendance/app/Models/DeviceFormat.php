<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which columns of an attendance machine's file hold the employee code and
 * the time (one column, or a date and a time), and how times are written.
 * Remembered from the last import of the company.
 */
#[Fillable(['organization_id', 'columns', 'datetime_format'])]
class DeviceFormat extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** Time formats offered (machines write local time). */
    public const FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'm/d/Y H:i:s', 'm/d/Y H:i', 'd-m-Y H:i:s', 'd.m.Y H:i'];

    protected $table = 'att_device_formats';

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
