<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Actions\UpdateOrganization;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\MembershipConflict;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Http\Requests\MoveOrganizationRequest;
use App\Platform\Tenancy\Http\Requests\StoreOrganizationRequest;
use App\Platform\Tenancy\Http\Requests\UpdateOrganizationRequest;
use App\Platform\Tenancy\Http\Resources\OrganizationResource;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\HierarchyService;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private CurrentContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        $perPage = max(1, min($request->integer('per_page', 50), 100));

        $organizations = Organization::query()
            ->visibleTo($this->context)
            ->orderBy('depth')
            ->orderBy('path')
            ->paginate($perPage);

        return OrganizationResource::collection($organizations);
    }

    public function show(string $organization): OrganizationResource
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $create): JsonResponse
    {
        $parent = $this->findVisible($request->validated('parent_id'));
        Gate::authorize('create', [Organization::class, $parent]);

        $organization = $create->handle(
            type: OrganizationType::from($request->validated('type')),
            attributes: Arr::except($request->validated(), ['parent_id', 'type']),
            parent: $parent,
            actor: $request->user(),
        );

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function update(UpdateOrganizationRequest $request, string $organization, UpdateOrganization $update): OrganizationResource
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('update', $organization);

        // Suspending the organization you are working in would lock you out mid-request.
        $status = $request->validated('status');
        if ($status !== null && $status !== OrganizationStatus::Active->value && $this->inCurrentChain($organization)) {
            throw MembershipConflict::currentContext();
        }

        return new OrganizationResource($update->handle($organization, $request->validated(), $request->user()));
    }

    public function settings(string $organization, OrganizationSettingsResolver $resolver): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('view', $organization);

        return response()->json(['data' => $resolver->explain($organization)]);
    }

    public function move(MoveOrganizationRequest $request, string $organization, HierarchyService $hierarchy): OrganizationResource
    {
        $organization = $this->findVisible($organization);
        $newParent = $this->findVisible($request->validated('new_parent_id'));
        Gate::authorize('move', [$organization, $newParent]);

        return new OrganizationResource($hierarchy->move(
            $organization,
            $newParent,
            $request->validated('reason'),
            $request->user(),
        ));
    }

    private function inCurrentChain(Organization $organization): bool
    {
        return $organization->is($this->context->organization())
            || $this->context->ancestors()->contains(fn (Organization $node) => $node->is($organization));
    }
}
