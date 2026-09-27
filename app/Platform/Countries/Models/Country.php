<?php

namespace App\Platform\Countries\Models;

use App\Platform\Support\HasTranslatedTexts;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a country data file (countries:sync), for listings and reports.
 * The data file is the source of truth; nothing is mass assignable.
 */
#[Table('countries')]
class Country extends Model
{
    use HasTranslatedTexts, HasUlids;

    /** @var list<string> Data labels in several languages. */
    public array $translatable = ['name'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'currency_decimals' => 'integer',
            'weekend_days' => 'array',
            'phone_format' => 'array',
            'address_format' => 'array',
            'locales' => 'array',
            'payment_gateways' => 'array',
            'merchant_gateways' => 'array',
        ];
    }
}
