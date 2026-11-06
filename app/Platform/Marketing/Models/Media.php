<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A picture of a company website (its WebP copies on the private disk). */
#[Table('mkt_media')]
#[Fillable(['site_id', 'name', 'alt', 'mime', 'size_bytes', 'width', 'height', 'paths', 'created_by'])]
class Media extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['alt' => 'array', 'paths' => 'array', 'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
