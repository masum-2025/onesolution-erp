<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One block of a draft page: a known type and its checked data (texts per language inside). */
#[Fillable(['organization_id', 'page_id', 'type', 'sort_order', 'data', 'version'])]
class Block extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'web_blocks';

    protected function casts(): array
    {
        return ['data' => 'array', 'sort_order' => 'integer', 'version' => 'integer'];
    }
}
