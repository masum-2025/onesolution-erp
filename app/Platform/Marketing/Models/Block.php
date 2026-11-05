<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A section of a page (slider, offer, pricing…): its kind and its checked data (see BlockTypes). */
#[Table('mkt_blocks')]
#[Fillable(['site_id', 'page_id', 'type', 'data', 'sort_order', 'is_visible', 'visible_from', 'visible_until', 'version'])]
class Block extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'visible_from' => 'date:Y-m-d',
            'visible_until' => 'date:Y-m-d',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
