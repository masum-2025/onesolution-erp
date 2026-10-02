<?php

namespace App\Platform\Partners\Models;

use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's brand: runtime tokens the app applies (no per-partner builds).
 * Written only by BrandService, which validates colors, texts and files.
 */
#[Table('partner_brands')]
#[Fillable([
    'partner_id', 'product_name', 'primary_color', 'secondary_color', 'band_colors', 'side_band_color', 'font_key',
    'logo_light_path', 'logo_dark_path', 'mark_path', 'favicon_path',
    'tagline', 'login_title', 'login_text', 'footer_text',
    'support_email', 'support_phone', 'terms_url', 'privacy_url', 'version',
])]
class PartnerBrand extends Model
{
    use HasUlids;

    /** Uploadable images and the column that stores each. */
    public const ASSETS = [
        'logo_light' => 'logo_light_path',
        'logo_dark' => 'logo_dark_path',
        'mark' => 'mark_path',
        'favicon' => 'favicon_path',
    ];

    protected function casts(): array
    {
        return [
            'band_colors' => 'array',
            'tagline' => 'array',
            'login_title' => 'array',
            'login_text' => 'array',
            'footer_text' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
