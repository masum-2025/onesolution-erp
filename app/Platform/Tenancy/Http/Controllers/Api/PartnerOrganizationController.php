<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Http\Resources\PartnerOrganizationResource;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Partner console: the partner's own client organizations, metadata only.
 * Client business data needs support access (Phase 5B).
 */
class PartnerOrganizationController extends Controller
{
    public function __construct(private CurrentContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = PerPage::from($request, 50);

        return PartnerOrganizationResource::collection(
            Organization::query()
                ->where('partner_id', $this->context->partner()->getKey())
                // ?groups=1: client groups at the top (to add a company to one).
                ->when($request->boolean('groups'), fn ($query) => $query->whereNull('parent_id')->where('type', 'group'))
                ->orderBy('root_id')
                ->orderBy('path')
                ->paginate($perPage)
        );
    }

    public function show(string $organization): PartnerOrganizationResource
    {
        $organization = Organization::query()
            ->where('partner_id', $this->context->partner()->getKey())
            ->whereKey($organization)
            ->first() ?? throw new OrganizationNotFound;

        return new PartnerOrganizationResource($organization);
    }
}
