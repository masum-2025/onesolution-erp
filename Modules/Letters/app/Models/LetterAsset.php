<?php

namespace Modules\Letters\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A letterhead, signature, stamp or logo image of a company's letters (kept privately; switched off, never deleted). */
#[Fillable(['organization_id', 'kind', 'name', 'path', 'mime', 'size_bytes', 'is_active', 'created_by'])]
class LetterAsset extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['letterhead', 'signature', 'stamp', 'logo'];

    protected $table = 'let_assets';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'is_active' => 'boolean'];
    }
}
