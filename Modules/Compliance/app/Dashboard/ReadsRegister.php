<?php

namespace Modules\Compliance\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Services\Place;
use Modules\Compliance\Services\Register;
use Modules\Compliance\Support\Validity;

/** The register at the signed-in unit and below, each line with its state and days left (its company's today). */
trait ReadsRegister
{
    /**
     * @return Collection<int, array{audit: Audit, state: string, days_left: int|null}>
     */
    private function standing(CurrentContext $context): Collection
    {
        $unit = $context->organization();
        $register = app(Register::class);
        $place = app(Place::class);
        $expiring = $register->expiringDays($unit);
        $todays = [];

        return $register->withLists($register->lines($unit))->map(function (Audit $audit) use ($place, $expiring, &$todays) {
            $today = $todays[$audit->company_id] ??= $place->today(Organization::query()->findOrFail($audit->company_id));

            return ['audit' => $audit, 'state' => Validity::state($audit->expires_on, $today, $expiring), 'days_left' => Validity::daysLeft($audit->expires_on, $today)];
        });
    }
}
