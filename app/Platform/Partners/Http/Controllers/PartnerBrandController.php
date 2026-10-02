<?php

namespace App\Platform\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Http\Requests\PoweredByRequest;
use App\Platform\Partners\Http\Requests\UpdateBrandRequest;
use App\Platform\Partners\Http\Requests\UploadBrandAssetRequest;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Partners\Services\BrandService;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: the partner's brand. Everyone in the console sees it;
 * only partner owners change it.
 */
class PartnerBrandController extends Controller
{
    use PartnerConsole;

    public function __construct(private BrandService $brands, private BrandResolver $resolver) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(UpdateBrandRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->brands->update($this->partner(), $request->validated(), $request->user());

        return response()->json(['data' => $this->payload(), 'message' => __('partners.messages.brand_saved')]);
    }

    public function storeAsset(UploadBrandAssetRequest $request, string $kind): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        abort_unless(isset(PartnerBrand::ASSETS[$kind]), 404);

        $this->brands->storeAsset($this->partner(), $kind, $request->file('file'), $request->user());

        return response()->json(['data' => $this->payload(), 'message' => __('partners.messages.image_saved')]);
    }

    public function destroyAsset(string $kind): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        abort_unless(isset(PartnerBrand::ASSETS[$kind]), 404);

        $this->brands->removeAsset($this->partner(), $kind, request()->user());

        return response()->json(['data' => $this->payload(), 'message' => __('partners.messages.image_removed')]);
    }

    public function poweredBy(PoweredByRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->brands->setPoweredBy($this->partner(), $request->boolean('show'), $request->user());

        return response()->json(['data' => $this->payload(), 'message' => __('partners.messages.brand_saved')]);
    }

    /**
     * The stored values (for the form) and the resolved brand (for the preview).
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $partner = $this->partner();
        $brand = $this->brands->brandOf($partner);

        return [
            'values' => [
                'product_name' => $brand->product_name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'band_colors' => $brand->band_colors ?? [],
                'side_band_color' => $brand->side_band_color,
                'font_key' => $brand->font_key,
                'tagline' => (object) ($brand->tagline ?? []),
                'login_title' => (object) ($brand->login_title ?? []),
                'login_text' => (object) ($brand->login_text ?? []),
                'footer_text' => (object) ($brand->footer_text ?? []),
                'support_email' => $brand->support_email,
                'support_phone' => $brand->support_phone,
                'terms_url' => $brand->terms_url,
                'privacy_url' => $brand->privacy_url,
            ],
            'resolved' => $this->resolver->for($partner),
            'fonts' => array_keys((array) config('branding.fonts')),
            'powered_by' => [
                'shown' => $this->resolver->for($partner)['powered_by'] !== null,
                'removable' => $this->brands->poweredByRemovable($partner),
            ],
            'can_edit' => $this->hasRole(PartnerUserRole::Owner),
        ];
    }
}
