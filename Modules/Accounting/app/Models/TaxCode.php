<?php

namespace Modules\Accounting\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One tax rate a company charges or pays (VAT 15%, zero-rated, exempt),
 * named in every language the client uses. Never deleted once used:
 * switched off instead. Rates are basis points (15% = 1500).
 */
#[Fillable(['organization_id', 'code', 'rate_bp', 'kind', 'applies_to', 'is_active', 'version'])]
class TaxCode extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['standard', 'reduced', 'zero', 'exempt'];

    public const SIDES = ['sales', 'purchases', 'both'];

    protected $table = 'acc_tax_codes';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    /** Usable on a document of this side (sales or purchases). */
    public function appliesTo(string $side): bool
    {
        return $this->is_active && ($this->applies_to === 'both' || $this->applies_to === $side);
    }
}
