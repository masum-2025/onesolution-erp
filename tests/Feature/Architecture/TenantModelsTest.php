<?php

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Permission;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Models\ModuleConsent;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\Models\Plan;
use App\Platform\Packaging\Models\PlanPrice;
use App\Platform\Packaging\Models\SectorPackage;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Models\PartnerModule;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Modules\Models\ModulePurgeRequest;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Rules\Models\RuleDefinitionRecord;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/**
 * Platform tables that are not owned by one organization. Anything else that
 * extends Model must be tenant-scoped. Adding to this list needs a review.
 */
const PLATFORM_MODELS = [
    User::class,
    Partner::class,
    PartnerUser::class,
    Organization::class,
    OrganizationMembership::class,
    AuditLog::class,
    // Module settings per organization level: resolution reads every ancestor's
    // row, so they cannot be tenant-scoped. Access goes through visible-organization
    // lookups and the modules.manage gate.
    OrganizationModule::class,
    ModuleConsent::class,
    ModulePurgeRequest::class,
    // Rule values live at platform, partner, plan, organization, role and user
    // scope; resolution reads the whole chain. Writes go through RuleService,
    // reads through visible-organization lookups.
    RuleDefinitionRecord::class,
    RuleValue::class,
    RuleValueHistory::class,
    // Permissions and templates are platform data. Roles are owned by an
    // organization but used by every unit below it, so members read their
    // ancestors' roles; writes go through RoleService, reads through
    // visible-organization lookups (like module settings and rule values).
    Permission::class,
    RoleTemplate::class,
    Role::class,
    MembershipRole::class,
    // Plans and sector packages are platform data. What a package did to a
    // company is written only by ApplySectorPackage and read through
    // visible-organization lookups (like module settings).
    Plan::class,
    PlanPrice::class,
    SectorPackage::class,
    OrganizationPackage::class,
    // Partner layer: owned by a partner, not an organization. Written only by
    // the partner console services and read through the partner context.
    PartnerBrand::class,
    PartnerDomain::class,
    PartnerModule::class,
    // Support grants are shared by a partner and a client; data exports are
    // written by the export service and job (which run without a tenant
    // context). Both are read only through visible-organization lookups.
    SupportGrant::class,
    DataExport::class,
];

/**
 * @return list<class-string<Model>>
 */
function applicationModels(): array
{
    $models = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

it('discovers the application models', function () {
    expect(applicationModels())->toContain(...PLATFORM_MODELS);
});

/**
 * @param  list<class-string<Model>>  $models
 * @return list<class-string<Model>> Models that do not use the trait.
 */
function modelsMissingTrait(array $models, string $trait): array
{
    return array_values(array_filter(
        $models,
        fn (string $model) => ! in_array($trait, class_uses_recursive($model), true),
    ));
}

it('uses ULID keys on every model', function () {
    expect(modelsMissingTrait(applicationModels(), HasUlids::class))->toBe([]);
});

it('scopes every business model to an organization', function () {
    $businessModels = array_values(array_diff(applicationModels(), PLATFORM_MODELS));

    expect(modelsMissingTrait($businessModels, BelongsToOrganization::class))->toBe([]);
});

it('uses no vendor-specific raw SQL in application code', function () {
    $pattern = '/\b(whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw|DB::statement|DB::unprepared|DB::select)\s*\(/';

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        expect(preg_match($pattern, $file->getContents()))->toBe(0, "Raw SQL found in {$file->getRelativePathname()}");
    }
});

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
