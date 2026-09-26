<?php

namespace App\Platform\Partners\Models;

use App\Platform\Modules\Enums\ModuleState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A module turned on / off (and maybe locked) by a partner for all of its
 * clients. Sits above every client's own settings in ModuleResolver.
 */
#[Table('partner_modules')]
#[Fillable(['partner_id', 'module_key', 'state', 'locked', 'updated_by'])]
class PartnerModule extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['state' => ModuleState::class, 'locked' => 'boolean'];
    }
}
