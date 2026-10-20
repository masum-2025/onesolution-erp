<?php

namespace Modules\EduWebsite\Http\Requests\Concerns;

use App\Platform\Tenancy\Models\Organization;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\Place;

/**
 * The website's main language (the one every text must have). Only that
 * value is read here; the controller still finds the unit among those the
 * person may see and checks the permission. Without a site: English.
 */
trait MainLocale
{
    protected function mainLocale(): string
    {
        $unit = Organization::query()->find((string) $this->route('organization'));
        if ($unit === null) {
            return 'en';
        }
        try {
            $place = app(Place::class);

            return (string) ($place->query(Site::class, $place->companyOf($unit))->value('default_locale') ?? 'en');
        } catch (WebsiteException) {
            return 'en';
        }
    }
}
