<?php

namespace Modules\EducationFees\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a fee is for, how often it is billed, where its income goes; name in each language.
 */
#[Fillable(['organization_id', 'code', 'name', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active', 'sort_order', 'version'])]
class FeeHead extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const FREQUENCIES = ['monthly', 'session', 'admission', 'per_credit', 'other'];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'fee_heads';

    protected function casts(): array
    {
        return ['sibling_discount' => 'boolean', 'late_fine' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer'];
    }
}
