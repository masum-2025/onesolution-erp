<?php

namespace App\Platform\Packaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Packaging\Http\PackageSummary;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Apply the package of a company's current sector, e.g. after its sector
 * changed. Creating a company applies it already. Applied once per package.
 */
class SectorPackageController extends Controller
{
    use FindsVisibleOrganizations;

    public function store(Request $request, string $organization, ApplySectorPackage $apply, PackageSummary $summary): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('update', $organization);

        $already = OrganizationPackage::query()
            ->where('organization_id', $organization->getKey())
            ->where('package_key', $organization->sector_key)
            ->exists();

        $record = $apply->handle($organization, $request->user()) ?? throw PackagingException::noPackage();

        return response()->json([
            'data' => $summary->for($record),
            'message' => __($already ? 'packaging.messages.already_applied' : 'packaging.messages.applied'),
        ], $already ? 200 : 201);
    }
}
