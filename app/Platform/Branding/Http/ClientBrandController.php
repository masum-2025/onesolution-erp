<?php

namespace App\Platform\Branding\Http;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Branding\Exceptions\BrandingException;
use App\Platform\Branding\Http\Requests\ClientBrandRequest;
use App\Platform\Branding\Services\ClientBrandService;
use App\Platform\Partners\Http\Requests\UploadBrandAssetRequest;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A client's own brand, set at its top organization by people holding
 * branding.manage there, where the partner allows sub-brands.
 */
class ClientBrandController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private ClientBrandService $brands, private BrandResolver $resolver) {}

    public function show(string $organization): JsonResponse
    {
        $root = $this->root($organization);

        return response()->json(['data' => $this->state($root)]);
    }

    public function update(ClientBrandRequest $request, string $organization): JsonResponse
    {
        $root = $this->editable($organization);
        $this->brands->update($root, $request->validated(), $request->user());

        return response()->json(['data' => $this->state($root), 'message' => __('branding.messages.saved')]);
    }

    public function storeLogo(UploadBrandAssetRequest $request, string $organization): JsonResponse
    {
        $root = $this->editable($organization);
        $this->brands->storeLogo($root, $request->file('file'), $request->user());

        return response()->json(['data' => $this->state($root), 'message' => __('branding.messages.logo_saved')]);
    }

    public function destroyLogo(Request $request, string $organization): JsonResponse
    {
        $root = $this->editable($organization);
        $this->brands->removeLogo($root, $request->user());

        return response()->json(['data' => $this->state($root), 'message' => __('branding.messages.logo_removed')]);
    }

    private function root(string $organization): Organization
    {
        $visible = $this->findVisible($organization);

        return $visible->isRoot() ? $visible : Organization::query()->findOrFail($visible->root_id);
    }

    private function editable(string $organization): Organization
    {
        $root = $this->root($organization);
        if (! Gate::allows('branding.manage', $root)) {
            throw BrandingException::forbidden();
        }

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Organization $root): array
    {
        $own = $this->brands->brandOf($root);
        $partnerBrand = $this->resolver->for($root->partner);

        return [
            'allowed' => $this->resolver->subBrandsAllowed($root->partner),
            'can_edit' => Gate::allows('branding.manage', $root),
            'own' => [
                'display_name' => $own->display_name,
                'primary_color' => $own->primary_color,
                'has_logo' => $own->logo_path !== null,
            ],
            // What the client's people see, and what they would see without it.
            'effective' => $this->resolver->for($root->partner, $root),
            'partner' => ['name' => $partnerBrand['name'], 'primary_color' => $partnerBrand['primary_color'], 'logo_url' => $partnerBrand['logo_url']],
        ];
    }
}
