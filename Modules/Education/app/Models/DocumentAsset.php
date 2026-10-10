<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An image used on designs (logo, signature, background, other), stored
 * privately and shown through short-lived signed links. Switched off, never
 * deleted: documents issued with it still print.
 */
#[Fillable(['organization_id', 'kind', 'name', 'path', 'mime', 'size_bytes', 'is_active', 'created_by'])]
class DocumentAsset extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['logo', 'signature', 'background', 'image'];

    protected $table = 'edu_document_assets';

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'is_active' => 'boolean'];
    }
}
