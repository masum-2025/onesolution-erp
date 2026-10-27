<?php

namespace Modules\Recruitment\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Services\Offers;
use Modules\Recruitment\Services\Place;

/** In the bell of people who may hire: job offers at the unit (and below) waiting for their approval (never their own). */
final class OffersToApprove implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || ! Gate::allows('recruitment.hire', $unit)) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $applications = $place->query(Application::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->pluck('id')->all();
        $offers = app(Offers::class);
        $count = $place->query(Offer::class, $company)->where('status', Offer::PENDING)->whereIn('application_id', $applications)->limit(100)->get()
            ->filter(fn (Offer $offer) => $offers->canApprove($company, $offer, $user))->count();

        return $count === 0 ? [] : [new AttentionItem('recruitment.offers_to_approve', __('recruitment::dashboard.attention_offers'), $count, '/recruitment/openings', 'warn')];
    }
}
