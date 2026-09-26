<?php

namespace App\Platform\Branding\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Branding\BrandResolver;
use App\Platform\Branding\Contrast;
use App\Platform\Branding\Exceptions\BrandingException;
use App\Platform\Branding\Models\ClientBrand;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A client's own sub-brand: name, color (checked for contrast like a
 * partner's) and logo (raster images only, private disk). Only where its
 * partner allows sub-brands; every change is in the client's audit log.
 */
class ClientBrandService
{
    private const IMAGE_TYPES = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    public function __construct(private BrandResolver $brands, private AuditLogger $audit) {}

    public function brandOf(Organization $root): ClientBrand
    {
        return ClientBrand::query()->firstOrNew(['organization_id' => $root->getKey()], ['version' => 1]);
    }

    /**
     * @param  array{display_name?: string|null, primary_color?: string|null}  $changes
     */
    public function update(Organization $root, array $changes, User $actor): ClientBrand
    {
        $this->assertAllowed($root);

        $color = $changes['primary_color'] ?? null;
        if (is_string($color)) {
            if (($problem = Contrast::problem($color)) !== null) {
                throw BrandingException::contrast($problem);
            }
            $changes['primary_color'] = strtoupper($color);
        }

        return DB::transaction(function () use ($root, $changes, $actor) {
            $brand = $this->brandOf($root);
            $brand->fill($changes);
            if ($brand->exists && ! $brand->isDirty()) {
                return $brand;
            }

            $old = array_intersect_key($brand->getOriginal(), $brand->getDirty());
            $brand->forceFill(['organization_id' => $root->getKey(), 'updated_by' => $actor->getKey(), 'version' => ($brand->exists ? $brand->version : 0) + 1])->save();

            $this->audit->record(
                action: 'organization.brand_updated',
                target: $brand,
                old: $old,
                new: array_intersect_key($brand->only(['display_name', 'primary_color']), $changes),
                actor: $actor,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            return $brand;
        });
    }

    public function storeLogo(Organization $root, UploadedFile $file, User $actor): ClientBrand
    {
        $this->assertAllowed($root);

        $extension = self::IMAGE_TYPES[$file->getMimeType()] ?? null;
        // The request checked type and size; this checks the content itself.
        if ($extension === null || @getimagesize($file->getRealPath()) === false) {
            throw BrandingException::badImage();
        }

        return DB::transaction(function () use ($root, $file, $extension, $actor) {
            $brand = $this->brandOf($root);
            $previous = $brand->logo_path;

            $path = $file->storeAs("client-brand/{$root->getKey()}", 'logo-'.Str::lower((string) Str::ulid()).'.'.$extension, 'local');
            $brand->forceFill([
                'organization_id' => $root->getKey(),
                'logo_path' => $path,
                'updated_by' => $actor->getKey(),
                'version' => ($brand->exists ? $brand->version : 0) + 1,
            ])->save();

            if ($previous !== null) {
                Storage::disk('local')->delete($previous);
            }

            $this->audit->record(
                action: 'organization.brand_logo_changed',
                target: $brand,
                new: ['bytes' => $file->getSize(), 'type' => $extension],
                actor: $actor,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            return $brand;
        });
    }

    public function removeLogo(Organization $root, User $actor): ClientBrand
    {
        $brand = $this->brandOf($root);
        if (! $brand->exists || $brand->logo_path === null) {
            return $brand;
        }

        Storage::disk('local')->delete($brand->logo_path);
        $brand->forceFill(['logo_path' => null, 'updated_by' => $actor->getKey(), 'version' => $brand->version + 1])->save();

        $this->audit->record(action: 'organization.brand_logo_changed', target: $brand, new: ['removed' => true], actor: $actor, organizationId: $root->getKey(), partnerId: $root->partner_id);

        return $brand;
    }

    private function assertAllowed(Organization $root): void
    {
        if (! $this->brands->subBrandsAllowed($root->partner)) {
            throw BrandingException::notAllowed();
        }
    }
}
