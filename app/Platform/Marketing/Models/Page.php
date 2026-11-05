<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A page of a company website: its address, title and search details per language. */
#[Table('mkt_pages')]
#[Fillable(['site_id', 'slug', 'is_home', 'title', 'seo', 'in_menu', 'sort_order', 'version'])]
class Page extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'title' => 'array',
            'seo' => 'array',
            'in_menu' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Block, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('sort_order');
    }
}
