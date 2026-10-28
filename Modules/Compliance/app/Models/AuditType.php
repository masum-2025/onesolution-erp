<?php

namespace Modules\Compliance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A kind of audit (initial, renewal, surveillance, follow-up): its code and name per language; switched off, never removed. */
#[Fillable(['organization_id', 'code', 'is_active', 'sort_order', 'version'])]
class AuditType extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'cmp_audit_types';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer'];
    }
}
