<?php

namespace App\Platform\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An old address of a site sent on to a new one (permanently), so links and search results keep working. */
#[Table('mkt_redirects')]
#[Fillable(['site_id', 'from_path', 'to_path', 'hits'])]
class Redirect extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['hits' => 'integer'];
    }
}
