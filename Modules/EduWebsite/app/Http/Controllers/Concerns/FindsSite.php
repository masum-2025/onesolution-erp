<?php

namespace Modules\EduWebsite\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\Place;

/**
 * The company of the unit in the address (visible to the context), the
 * permission there, and its website and records (found only inside that
 * site; anything else is the same 404).
 */
trait FindsSite
{
    use FindsVisibleOrganizations;

    protected function company(string $organization, string $permission): Organization
    {
        $company = app(Place::class)->companyOf($this->findVisible($organization));
        Gate::authorize($permission, $company);

        return $company;
    }

    protected function siteOf(Organization $company): Site
    {
        return app(Place::class)->query(Site::class, $company)->first() ?? throw WebsiteException::noSite();
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    protected function recordOf(string $model, Organization $company, Site $site, string $id, WebsiteException $missing, string $siteColumn = 'site_id'): Model
    {
        return app(Place::class)->query($model, $company)->where($siteColumn, $site->getKey())->whereKey($id)->first() ?? throw $missing;
    }
}
