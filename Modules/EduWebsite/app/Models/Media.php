<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An image (with smaller copies) or a PDF of the website, kept on the private disk. */
#[Fillable(['organization_id', 'site_id', 'kind', 'name', 'mime', 'size_bytes', 'width', 'height', 'paths', 'created_by'])]
class Media extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'web_media';

    protected function casts(): array
    {
        return ['paths' => 'array', 'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
