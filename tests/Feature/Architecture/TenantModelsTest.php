<?php

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Permission;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Audit\AuditLog;
use App\Platform\Audit\Exports\AuditExport;
use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\InvoiceLine;
use App\Platform\Billing\Models\Payout;
use App\Platform\Billing\Models\TrialGrant;
use App\Platform\Billing\Models\WholesalePrice;
use App\Platform\Branding\Models\ClientBrand;
use App\Platform\Countries\Models\Country;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Models\Passkey;
use App\Platform\Identity\Models\RecoveryCode;
use App\Platform\Identity\Models\TotpSecret;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Invitations\Models\Invitation;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Modules\Models\ModuleConsent;
use App\Platform\Modules\Models\ModulePurgeRequest;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Notifications\Models\PartnerSmsSender;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\OfflineLease;
use App\Platform\Offline\Models\QuarantinedOperation;
use App\Platform\Offline\Models\SyncOperationRecord;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\PartnerPlanPrice;
use App\Platform\Packaging\Models\Plan;
use App\Platform\Packaging\Models\PlanPrice;
use App\Platform\Packaging\Models\SectorPackage;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Models\PartnerModule;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use App\Platform\Portal\Models\PortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\Models\RuleDefinitionRecord;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use App\Platform\Transfers\Models\ClientTransfer;
use App\Platform\Transfers\Models\TransferCode;
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
    // Audit exports (9-1): same pattern as data exports; read through visible-organization lookups.
    AuditExport::class,
    // Security alerts (9-2): platform operations data; organization/partner ids only say what an alert is about.
    SecurityAlert::class,
    // Billing (5B-3): commercial records between the platform, partners and
    // clients. Partner plans and wholesale prices are partner or platform data;
    // subscriptions, invoices and commissions are read by the partner console
    // and the billing run across clients, always filtered by the partner or
    // organization taken from the context.
    PartnerPlan::class,
    PartnerPlanPrice::class,
    Subscription::class,
    WholesalePrice::class,
    Invoice::class,
    InvoiceLine::class,
    Commission::class,
    Payout::class,
    // Branded messages (5B-3b): partner settings (sending domain, SMS sender,
    // wording) and the record of messages sent, written by queued jobs without
    // a tenant context; the console reads them filtered by the context's partner.
    PartnerMailDomain::class,
    PartnerSmsSender::class,
    NotificationTemplate::class,
    NotificationDelivery::class,
    // Data ownership (5B-4): a transfer spans two partners and is read by both
    // consoles and by the client; codes and legal documents are partner or
    // platform data; acceptances are read only through the client account.
    TransferCode::class,
    ClientTransfer::class,
    LegalDocument::class,
    DocumentAcceptance::class,
    // Sub-brands and the partner API (5B-5): a client's brand is read at
    // sign-in (before any context) and by the partner's brand resolver;
    // keys are partner data; invitations are opened by people not signed in.
    ClientBrand::class,
    PartnerApiKey::class,
    Invitation::class,
    // Identity (Phase 5C): a person's own codes and devices, before and outside any organization.
    OtpChallenge::class,
    UserSession::class,
    // Two-step sign-in (Phase 8-1): one identity's second steps, used before any context.
    TotpSecret::class,
    RecoveryCode::class,
    Passkey::class,
    // Self-serve billing (5C-2): payments and gateway messages arrive from the
    // gateway without a signed-in person; trial grants are checked across
    // accounts. People read payments only through their context's organization.
    Payment::class,
    GatewayEvent::class,
    TrialGrant::class,
    // Portals (5C-4): invitations are opened and links made while joining, before
    // any context; staff and members read them filtered by the context's
    // organization and membership.
    PortalInvitation::class,
    PortalLink::class,
    // Country facts (Phase 6): platform reference data.
    Country::class,
    // Offline sync (Phase 7): checked by the sync endpoint before any tenant
    // context exists (a removed device or ended membership has none); people
    // read them only through their own account or their context's organization.
    Device::class,
    OfflineLease::class,
    SyncOperationRecord::class,
    QuarantinedOperation::class,
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
