<?php

namespace App\Platform\Marketing\Models;

use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner's company website (the house partner's is the platform's own).
 * Written only by the Marketing services, which check every value.
 */
#[Table('mkt_sites')]
#[Fillable(['partner_id', 'template', 'settings', 'locales', 'default_locale', 'status', 'published', 'published_at', 'published_by', 'cache_version', 'version'])]
class Site extends Model
{
    use HasUlids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const OFFLINE = 'offline';

    public const TEMPLATES = ['aurora', 'corporate', 'studio'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'locales' => 'array',
            'published' => 'array',
            'published_at' => 'datetime',
            'cache_version' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('sort_order');
    }
}
