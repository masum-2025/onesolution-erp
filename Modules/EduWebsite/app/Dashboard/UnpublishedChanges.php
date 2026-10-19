<?php

namespace Modules\EduWebsite\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\EduWebsite\Services\Place;
use Modules\EduWebsite\Services\Sites;

/** In the bell of people who may publish: the draft pages differ from what the public sees. */
final class UnpublishedChanges implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || $unit->type === OrganizationType::Group) {
            return [];
        }
        $company = app(Place::class)->companyOf($unit);
        if (! Gate::allows('edu_website.publish', $company)) {
            return [];
        }
        $sites = app(Sites::class);
        $site = $sites->of($company);
        if ($site === null || $site->published === null || ! $sites->hasChanges($company, $site)) {
            return [];
        }

        return [new AttentionItem('edu_website.unpublished', __('edu_website::dashboard.unpublished'), 1, '/website', 'info')];
    }
}
