<?php

namespace App\Platform\Sites;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public site's address (central): the slug that names it under the
 * sites domain and in "/s/{slug}", for one organization and module, and
 * its own domain once proven by DNS (domain_verified_at).
 */
#[Fillable(['organization_id', 'module', 'slug', 'domain', 'domain_token', 'domain_verified_at'])]
class SiteAddress extends Model
{
    use HasUlids;

    protected $table = 'site_addresses';

    protected $hidden = ['domain_token'];

    protected function casts(): array
    {
        return ['domain_verified_at' => 'datetime'];
    }

    /** The DNS name of the TXT record that proves the domain. */
    public function txtName(): string
    {
        return '_onesolution-site.'.$this->domain;
    }

    public function txtValue(): string
    {
        return 'onesolution-site='.$this->domain_token;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
