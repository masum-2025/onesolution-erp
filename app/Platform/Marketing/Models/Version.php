<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An earlier published snapshot of a site, kept to go back to. */
#[Table('mkt_versions')]
#[Fillable(['site_id', 'snapshot', 'published_by', 'published_at'])]
class Version extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }
}
