<?php

namespace App\Platform\Branding\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A client's own name, color and logo (its top organization), shown to its
 * own people where its partner allows sub-brands. The logo is on the
 * private disk and served by /client-brand-assets/{organization}/logo.
 */
#[Table('client_brands')]
#[Fillable(['display_name', 'primary_color'])]
class ClientBrand extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
