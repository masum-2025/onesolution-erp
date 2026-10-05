<?php

namespace Modules\Pos\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A counter (till) of a branch, selling out of one warehouse, taking some payment methods. */
#[Fillable(['organization_id', 'unit_id', 'warehouse_id', 'code', 'payment_methods', 'is_active', 'version'])]
class Register extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const METHODS = ['cash', 'card', 'mobile'];

    protected $table = 'pos_registers';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'payment_methods' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
