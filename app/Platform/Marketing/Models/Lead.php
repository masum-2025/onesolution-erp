<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who wrote through a company website's contact form. */
#[Table('mkt_leads')]
#[Fillable(['site_id', 'name', 'phone', 'email', 'company', 'message', 'page_slug', 'locale', 'status', 'note', 'handled_by'])]
class Lead extends Model
{
    use HasUlids;

    public const STATUSES = ['new', 'contacted', 'won', 'closed'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
