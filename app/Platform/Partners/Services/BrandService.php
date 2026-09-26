<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Branding\Contrast;
use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every change to a partner's brand: colors checked for contrast, images
 * checked and stored privately, the version bumped (new asset URLs), and
 * the change audited.
 */
class BrandService
{
    /** Stored image types by detected MIME (never trusting the file name). */
    private const IMAGE_TYPES = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    public function __construct(
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private RuleService $ruleService,
        private RuleTargets $targets,
    ) {}

    public function brandOf(Partner $partner): PartnerBrand
    {
        return PartnerBrand::query()->firstOrNew(['partner_id' => $partner->getKey()], ['version' => 1]);
    }

    /**
     * @param  array<string, mixed>  $changes  Validated fields (see UpdateBrandRequest).
     */
    public function update(Partner $partner, array $changes, User $actor): PartnerBrand
    {
        foreach (['primary_color', 'secondary_color'] as $field) {
            $color = $changes[$field] ?? null;
            if (is_string($color) && ($problem = Contrast::problem($color, $field === 'primary_color')) !== null) {
                throw PartnerException::contrast($field, $problem);
            }
        }

        foreach (['primary_color', 'secondary_color'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = strtoupper($changes[$field]);
            }
        }

        return DB::transaction(function () use ($partner, $changes, $actor) {
            $brand = $this->brandOf($partner);
            $brand->fill($changes);

            if (! $brand->isDirty() && $brand->exists) {
                return $brand;
            }

            $old = array_intersect_key($brand->getOriginal(), $brand->getDirty());
            $brand->version = ($brand->exists ? $brand->version : 0) + 1;
            $brand->save();

            $this->audit->record(
                action: 'partner.brand_updated',
                target: $brand,
                old: $old,
                new: array_intersect_key($brand->getAttributes(), $old + $changes),
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $brand;
        });
    }

    public function storeAsset(Partner $partner, string $kind, UploadedFile $file, User $actor): PartnerBrand
    {
        $column = PartnerBrand::ASSETS[$kind] ?? throw new \InvalidArgumentException("Unknown brand asset [{$kind}].");
        $extension = self::IMAGE_TYPES[$file->getMimeType()] ?? null;

        // The request validated type and size; this re-checks the content itself.
        if ($extension === null || @getimagesize($file->getRealPath()) === false) {
            throw new \InvalidArgumentException('Not a supported image.');
        }

        return DB::transaction(function () use ($partner, $kind, $column, $file, $extension, $actor) {
            $brand = $this->brandOf($partner);
            $previous = $brand->{$column};

            $path = $file->storeAs("brand/{$partner->getKey()}", $kind.'-'.Str::lower((string) Str::ulid()).'.'.$extension, 'local');

            $brand->{$column} = $path;
            $brand->version = ($brand->exists ? $brand->version : 0) + 1;
            $brand->save();

            if ($previous !== null) {
                Storage::disk('local')->delete($previous);
            }

            $this->audit->record(
                action: 'partner.brand_asset_changed',
                target: $brand,
                new: ['asset' => $kind, 'bytes' => $file->getSize(), 'type' => $extension],
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $brand;
        });
    }

    public function removeAsset(Partner $partner, string $kind, User $actor): PartnerBrand
    {
        $column = PartnerBrand::ASSETS[$kind] ?? throw new \InvalidArgumentException("Unknown brand asset [{$kind}].");
        $brand = $this->brandOf($partner);

        if (! $brand->exists || $brand->{$column} === null) {
            return $brand;
        }

        return DB::transaction(function () use ($partner, $kind, $column, $brand, $actor) {
            Storage::disk('local')->delete($brand->{$column});
            $brand->{$column} = null;
            $brand->version++;
            $brand->save();

            $this->audit->record(action: 'partner.brand_asset_removed', target: $brand, old: ['asset' => $kind], actor: $actor, partnerId: $partner->getKey());

            return $brand;
        });
    }

    public function poweredByRemovable(Partner $partner): bool
    {
        return (bool) $this->rules->get('branding.powered_by_removable', $this->contexts->forPartner($partner));
    }

    /**
     * Show or hide the "Powered by" badge. Hiding needs the platform's permission for this partner.
     */
    public function setPoweredBy(Partner $partner, bool $show, User $actor): void
    {
        if (! $show && ! $this->poweredByRemovable($partner)) {
            throw PartnerException::poweredByLocked();
        }

        $this->ruleService->set(
            $this->targets->partner($partner),
            'branding.show_powered_by',
            RuleMode::Set,
            $show,
            $show ? 'Partner shows the "Powered by" badge' : 'Partner hides the "Powered by" badge',
            $actor,
            trusted: true,
        );
    }
}
