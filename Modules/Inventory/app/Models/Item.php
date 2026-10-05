<?php

namespace Modules\Inventory\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a company keeps or sells: a stock item keeps a balance per
 * warehouse; a non-stock item (a service) is sold or bought without one.
 * Switched off, never removed, once used.
 */
#[Fillable(['organization_id', 'sku', 'barcode', 'category_id', 'unit_id', 'kind', 'track_batches', 'sale_price_minor', 'tax_code_id', 'reorder_level_milli', 'reorder_quantity_milli', 'description', 'is_active', 'version'])]
class Item extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['stock', 'non_stock'];

    public function keepsStock(): bool
    {
        return $this->kind === 'stock';
    }

    protected $table = 'inv_items';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'track_batches' => 'boolean',
            'sale_price_minor' => 'integer',
            'reorder_level_milli' => 'integer',
            'reorder_quantity_milli' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
