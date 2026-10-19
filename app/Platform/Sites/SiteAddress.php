<?php

namespace App\Platform\Sites;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public site's address (central): the slug that names it under the
 * sites domain and in "/s/{slug}", for one organization and module.
 */
#[Fillable(['organization_id', 'module', 'slug'])]
class SiteAddress extends Model
{
    use HasUlids;

    protected $table = 'site_addresses';

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
