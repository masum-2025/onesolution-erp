<?php

use App\Platform\Access\Http\Controllers\PermissionController;
use App\Platform\Access\Http\Controllers\RoleController;
use App\Platform\Appearance\Http\AppearanceController;
use App\Platform\Attention\Http\AttentionController;
use App\Platform\Audit\Http\AdvancedAuditController;
use App\Platform\Audit\Http\AuditLogController;
use App\Platform\Billing\Http\Controllers\OrganizationBillingController;
use App\Platform\Billing\Http\Controllers\PartnerBillingController;
use App\Platform\Billing\Http\Controllers\PartnerPlansController;
use App\Platform\Billing\Http\Controllers\PartnerSubscriptionController;
use App\Platform\Billing\Http\Controllers\SelfServeBillingController;
use App\Platform\Branding\Http\ClientBrandController;
use App\Platform\Countries\Http\CountryController;
use App\Platform\DataExport\Http\DataExportController;
use App\Platform\Identity\Http\Controllers\AccountController;
use App\Platform\Identity\Http\Controllers\MfaResetController;
use App\Platform\Identity\Http\Controllers\MyDataController;
use App\Platform\Identity\Http\Controllers\SecurityController;
use App\Platform\Identity\Http\Controllers\UpgradeController;
use App\Platform\Legal\Http\Controllers\ClientLegalController;
use App\Platform\Legal\Http\Controllers\PartnerLegalController;
use App\Platform\Modules\Http\Controllers\MenuController;
use App\Platform\Modules\Http\Controllers\ModuleConsentController;
use App\Platform\Modules\Http\Controllers\ModuleController;
use App\Platform\Modules\Http\Controllers\ModulePurgeController;
use App\Platform\Notifications\Http\Controllers\PartnerMessagingController;
use App\Platform\Notifications\Http\Controllers\PartnerTemplateController;
use App\Platform\Offline\Http\Controllers\MyDevicesController;
use App\Platform\Offline\Http\Controllers\OfflineAdminController;
use App\Platform\Offline\Http\Controllers\OfflineDeviceController;
use App\Platform\Offline\Http\Controllers\SyncController;
use App\Platform\Packaging\Http\Controllers\CatalogController;
use App\Platform\Packaging\Http\Controllers\PartnerPlanController;
use App\Platform\Packaging\Http\Controllers\SectorPackageController;
use App\Platform\Packaging\Http\Controllers\UsageController;
use App\Platform\PartnerApi\Http\Controllers\PartnerApiKeyController;
use App\Platform\PartnerApi\Http\Controllers\PartnerApiV1Controller;
use App\Platform\Partners\Http\Controllers\PartnerBrandController;
use App\Platform\Partners\Http\Controllers\PartnerClientController;
use App\Platform\Partners\Http\Controllers\PartnerDomainController;
use App\Platform\Partners\Http\Controllers\PartnerModuleController;
use App\Platform\Payments\Http\Controllers\MerchantAccountController;
use App\Platform\Portal\Http\Controllers\PortalAdminController;
use App\Platform\Portal\Http\Controllers\PortalMemberController;
use App\Platform\Rules\Http\Controllers\OrganizationRuleController;
use App\Platform\Rules\Http\Controllers\PartnerRuleController;
use App\Platform\Rules\Http\Controllers\RuleApprovalController;
use App\Platform\SupportAccess\Http\Controllers\ClientSupportController;
use App\Platform\SupportAccess\Http\Controllers\PartnerSupportController;
use App\Platform\Tenancy\Http\Controllers\Api\AuthController;
use App\Platform\Tenancy\Http\Controllers\Api\MeController;
use App\Platform\Tenancy\Http\Controllers\Api\MemberController;
use App\Platform\Tenancy\Http\Controllers\Api\OrganizationController;
use App\Platform\Tenancy\Http\Controllers\Api\PartnerOrganizationController;
use App\Platform\Transfers\Http\Controllers\ClientProviderController;
use App\Platform\Transfers\Http\Controllers\PartnerTransferController;
use Illuminate\Support\Facades\Route;

/*
| Tenancy foundation (Phase 1). The active organization / partner always
| comes from the token (see ResolveOrganization / ResolvePartner).
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:tenancy-login');
    // The second step for API clients (Phase 8-1).
    Route::post('two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:two-factor');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('context', [AuthController::class, 'enterContext'])->middleware('throttle:tenancy-sensitive');
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::get('me', MeController::class)->middleware('auth:sanctum');

// Offline sync (Phase 7): signed in, outside the organization middleware on purpose
// (a removed device or ended membership must still hand over its changes and be told to wipe).
Route::middleware(['auth:sanctum', 'throttle:offline-sync'])->group(function () {
    Route::post('sync', [SyncController::class, 'sync']);
    Route::post('offline/devices/{device}/wiped', [SyncController::class, 'wiped'])->where('device', '[0-9A-Za-z]{26}');
});

// My account (Phase 5C-1): the person's own identity, in any context.
Route::middleware('auth:sanctum')->prefix('me')->group(function () {
    Route::get('account', [AccountController::class, 'show']);
    Route::patch('account', [AccountController::class, 'update']);
    // The person's own look of the app: template, color, readability.
    Route::put('appearance', [AppearanceController::class, 'update'])->middleware('throttle:tenancy-sensitive');
    Route::post('onboarding', [AccountController::class, 'onboarding']);
    Route::get('sessions', [AccountController::class, 'sessions']);
    Route::delete('sessions/{session}', [AccountController::class, 'endSession'])->where('session', '[0-9A-Za-z]{26}');
    Route::delete('sessions', [AccountController::class, 'endOtherSessions']);

    // My data and "delete my account" (Phase 5C-3).
    Route::get('data', [MyDataController::class, 'download'])->middleware('throttle:my-data');
    // The person's own offline devices (Phase 7).
    Route::get('devices', [MyDevicesController::class, 'index']);
    Route::delete('devices/{device}', [MyDevicesController::class, 'destroy'])->where('device', '[0-9A-Za-z]{26}');
    Route::delete('deletion', [MyDataController::class, 'cancelDeletion']);

    // Security: two-step sign-in (Phase 8-1). Adding or removing a second step needs a recent one.
    Route::get('security', [SecurityController::class, 'show']);
    Route::middleware('throttle:two-factor')->group(function () {
        Route::post('security/confirm/options', [SecurityController::class, 'confirmOptions']);
        Route::post('security/confirm', [SecurityController::class, 'confirm']);
        Route::post('security/totp/confirm', [SecurityController::class, 'confirmTotp']);
    });
    Route::middleware(['throttle:tenancy-sensitive', 'two_factor.recent'])->group(function () {
        Route::post('security/totp', [SecurityController::class, 'startTotp']);
        Route::delete('security/totp', [SecurityController::class, 'disableTotp']);
        Route::post('security/recovery-codes', [SecurityController::class, 'recoveryCodes']);
        Route::post('security/passkeys/options', [SecurityController::class, 'passkeyOptions']);
        Route::post('security/passkeys', [SecurityController::class, 'storePasskey']);
        Route::delete('security/passkeys/{passkey}', [SecurityController::class, 'destroyPasskey'])->where('passkey', '[0-9A-Za-z]{26}');
    });
    Route::patch('security/passkeys/{passkey}', [SecurityController::class, 'renamePasskey'])->where('passkey', '[0-9A-Za-z]{26}');

    Route::middleware('throttle:identity-code')->group(function () {
        Route::post('deletion', [MyDataController::class, 'requestDeletion']);
        Route::put('password', [AccountController::class, 'password']);
        Route::post('contact', [AccountController::class, 'contact']);
        Route::post('contact/verify', [AccountController::class, 'verifyContact']);
        Route::delete('phone', [AccountController::class, 'removePhone']);
    });
});

// Plans and sector packages (Phase 5): catalog data for pickers, any signed-in user.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('plans', [CatalogController::class, 'plans']);
    Route::get('sectors', [CatalogController::class, 'sectors']);
});

Route::middleware(['auth:sanctum', 'org'])->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('organizations/{organization}', [OrganizationController::class, 'update']);
    Route::get('organizations/{organization}/settings', [OrganizationController::class, 'settings']);
    Route::post('organizations/{organization}/move', [OrganizationController::class, 'move'])
        ->middleware('throttle:tenancy-sensitive');

    Route::get('organizations/{organization}/members', [MemberController::class, 'index']);
    Route::post('organizations/{organization}/members', [MemberController::class, 'store'])
        ->middleware('throttle:tenancy-sensitive');
    Route::patch('organizations/{organization}/members/{membership}', [MemberController::class, 'update']);
    Route::put('organizations/{organization}/members/{membership}/roles', [MemberController::class, 'updateRoles'])
        ->middleware(['throttle:tenancy-sensitive', 'two_factor.recent']);

    // Two-step sign-in resets (Phase 8-1): one admin asks, another approves.
    Route::get('organizations/{organization}/mfa-resets', [MfaResetController::class, 'index']);
    Route::middleware(['throttle:tenancy-sensitive', 'two_factor.recent'])->group(function () {
        Route::post('organizations/{organization}/members/{membership}/mfa-reset', [MfaResetController::class, 'store']);
        Route::post('organizations/{organization}/mfa-resets/{reset}/approve', [MfaResetController::class, 'approve']);
        Route::post('organizations/{organization}/mfa-resets/{reset}/reject', [MfaResetController::class, 'reject']);
    });

    // Roles and permissions (Phase 4)
    Route::get('organizations/{organization}/permissions', [PermissionController::class, 'index']);
    Route::get('organizations/{organization}/role-templates', [PermissionController::class, 'templates']);
    Route::get('organizations/{organization}/roles', [RoleController::class, 'index']);
    Route::get('organizations/{organization}/roles/{role}', [RoleController::class, 'show']);
    Route::middleware(['throttle:tenancy-sensitive', 'two_factor.recent'])->group(function () {
        Route::post('organizations/{organization}/roles', [RoleController::class, 'store']);
        Route::patch('organizations/{organization}/roles/{role}', [RoleController::class, 'update']);
        Route::delete('organizations/{organization}/roles/{role}', [RoleController::class, 'destroy']);
    });

    // Trust and data ownership (Phase 5B-2): audit log, support access, data export.
    Route::get('organizations/{organization}/audit-log', AuditLogController::class);
    // Reports and CSV exports of the audit log (advanced_audit, Phase 9-1).
    Route::middleware('module:advanced_audit')->group(function () {
        Route::get('organizations/{organization}/audit-log/report', [AdvancedAuditController::class, 'report'])->middleware('throttle:audit-report');
        Route::get('organizations/{organization}/audit-log/exports', [AdvancedAuditController::class, 'exports']);
        Route::post('organizations/{organization}/audit-log/exports', [AdvancedAuditController::class, 'store'])->middleware('throttle:data-export');
        Route::get('organizations/{organization}/audit-log/exports/{export}/link', [AdvancedAuditController::class, 'link']);
    });
    Route::get('organizations/{organization}/support-grants', [ClientSupportController::class, 'index']);
    Route::get('organizations/{organization}/exports', [DataExportController::class, 'index']);
    Route::get('organizations/{organization}/exports/{export}/link', [DataExportController::class, 'link']);
    Route::post('organizations/{organization}/exports', [DataExportController::class, 'store'])->middleware('throttle:data-export');
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('organizations/{organization}/support-grants/{grant}/approve', [ClientSupportController::class, 'approve'])->middleware('two_factor.recent');
        Route::post('organizations/{organization}/support-grants/{grant}/reject', [ClientSupportController::class, 'reject']);
        Route::post('organizations/{organization}/support-grants/{grant}/revoke', [ClientSupportController::class, 'revoke']);
    });

    // The client's own brand (Phase 5B-5).
    Route::get('organizations/{organization}/brand', [ClientBrandController::class, 'show']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::patch('organizations/{organization}/brand', [ClientBrandController::class, 'update']);
        Route::delete('organizations/{organization}/brand/logo', [ClientBrandController::class, 'destroyLogo']);
    });
    Route::post('organizations/{organization}/brand/logo', [ClientBrandController::class, 'storeLogo'])->middleware('throttle:partner-heavy');

    // The client's provider, legal documents and moving provider (Phase 5B-4).
    Route::get('organizations/{organization}/provider', [ClientProviderController::class, 'show']);
    Route::get('organizations/{organization}/legal/{kind}', [ClientLegalController::class, 'show']);
    Route::post('organizations/{organization}/legal/{kind}/accept', [ClientLegalController::class, 'accept'])->middleware('throttle:tenancy-sensitive');
    Route::middleware('throttle:client-transfer')->group(function () {
        Route::post('organizations/{organization}/transfer/preview', [ClientProviderController::class, 'preview']);
        Route::post('organizations/{organization}/transfer', [ClientProviderController::class, 'store'])->middleware('two_factor.recent');
    });
    Route::post('organizations/{organization}/transfer/{transfer}/cancel', [ClientProviderController::class, 'cancel'])->middleware('throttle:tenancy-sensitive');

    // The client's own plan and invoices (Phase 5B-3).
    Route::get('organizations/{organization}/billing', [OrganizationBillingController::class, 'show']);
    Route::get('organizations/{organization}/billing/invoices/{invoice}', [OrganizationBillingController::class, 'invoice']);

    // Offline mode (Phase 7): setting up a device and its lease; an organization's devices and held changes.
    Route::middleware('module:offline_mode')->group(function () {
        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('offline/devices', [OfflineDeviceController::class, 'store']);
            Route::post('offline/devices/{device}/lease', [OfflineDeviceController::class, 'lease'])->where('device', '[0-9A-Za-z]{26}');
            Route::post('organizations/{organization}/offline/devices/{device}/revoke', [OfflineAdminController::class, 'revoke']);
            Route::post('organizations/{organization}/offline/held/{held}/release', [OfflineAdminController::class, 'release'])->middleware('two_factor.recent');
            Route::post('organizations/{organization}/offline/held/{held}/discard', [OfflineAdminController::class, 'discard']);
        });
        Route::get('organizations/{organization}/offline', [OfflineAdminController::class, 'show']);
    });

    // B2B2C portal (Phase 5C-4): the client's side, and a portal member's own records.
    Route::middleware('module:client_portal')->group(function () {
        Route::get('organizations/{organization}/portal', [PortalAdminController::class, 'show']);
        Route::get('organizations/{organization}/portal/kinds/{kind}/records', [PortalAdminController::class, 'search'])->where('kind', '[a-z0-9_.]+');
        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('organizations/{organization}/portal/invitations', [PortalAdminController::class, 'invite']);
            Route::delete('organizations/{organization}/portal/invitations/{invitation}', [PortalAdminController::class, 'revokeInvitation']);
            Route::post('organizations/{organization}/portal/links/{link}/approve', [PortalAdminController::class, 'approve']);
            Route::post('organizations/{organization}/portal/links/{link}/reject', [PortalAdminController::class, 'reject']);
            Route::post('organizations/{organization}/portal/links/{link}/revoke', [PortalAdminController::class, 'revoke']);
        });

        Route::get('portal', [PortalMemberController::class, 'index']);
        Route::get('portal/records/{link}', [PortalMemberController::class, 'show'])->where('link', '[0-9A-Za-z]{26}');
    });

    // A client's own payment gateway accounts (Phase 6), at its company.
    Route::middleware('module:online_payments')->group(function () {
        Route::get('organizations/{organization}/merchant-accounts', [MerchantAccountController::class, 'index']);
        $account = '[0-9A-Za-z]{26}';
        // Each of these asks the gateway or checks a password: a few per minute per person.
        Route::middleware('throttle:merchant-accounts')->group(function () use ($account) {
            Route::post('organizations/{organization}/merchant-accounts', [MerchantAccountController::class, 'store'])->middleware('two_factor.recent');
            Route::patch('organizations/{organization}/merchant-accounts/{account}', [MerchantAccountController::class, 'update'])->where('account', $account)->middleware('two_factor.recent');
            Route::post('organizations/{organization}/merchant-accounts/{account}/test', [MerchantAccountController::class, 'test'])->where('account', $account);
            Route::post('organizations/{organization}/merchant-accounts/{account}/approve', [MerchantAccountController::class, 'approve'])->where('account', $account)->middleware('two_factor.recent');
            Route::post('organizations/{organization}/merchant-accounts/{account}/enable', [MerchantAccountController::class, 'enable'])->where('account', $account)->middleware('two_factor.recent');
        });
        Route::middleware('throttle:tenancy-sensitive')->group(function () use ($account) {
            Route::post('organizations/{organization}/merchant-accounts/{account}/reject', [MerchantAccountController::class, 'reject'])->where('account', $account);
            Route::post('organizations/{organization}/merchant-accounts/{account}/disable', [MerchantAccountController::class, 'disable'])->where('account', $account);
        });
    });

    // A personal workspace becomes a company (Phase 5C-3): owner only.
    Route::get('organizations/{organization}/upgrade', [UpgradeController::class, 'preview']);
    Route::post('organizations/{organization}/upgrade', [UpgradeController::class, 'store'])->middleware('throttle:tenancy-sensitive');

    // Self-serve billing (Phase 5C-2): buy, pay and change a personal plan.
    // Open while read-only for an overdue bill, so it can always be settled.
    Route::get('organizations/{organization}/billing/self-serve', [SelfServeBillingController::class, 'show']);
    Route::get('organizations/{organization}/billing/payments/{payment}', [SelfServeBillingController::class, 'payment'])->where('payment', '[0-9A-Za-z]{26}');
    Route::middleware('throttle:payments-start')->group(function () {
        Route::post('organizations/{organization}/billing/quote', [SelfServeBillingController::class, 'quote']);
        Route::post('organizations/{organization}/billing/checkout', [SelfServeBillingController::class, 'checkout']);
        Route::post('organizations/{organization}/billing/invoices/{invoice}/pay', [SelfServeBillingController::class, 'payInvoice'])->where('invoice', '[0-9A-Za-z]{26}');
    });
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('organizations/{organization}/billing/trial', [SelfServeBillingController::class, 'trial']);
        Route::post('organizations/{organization}/billing/free', [SelfServeBillingController::class, 'toFree']);
        Route::post('organizations/{organization}/billing/keep-plan', [SelfServeBillingController::class, 'keepPlan']);
    });

    // Plan usage and sector packages (Phase 5)
    Route::get('organizations/{organization}/usage', UsageController::class);
    Route::post('organizations/{organization}/sector-package', [SectorPackageController::class, 'store'])
        ->middleware('throttle:tenancy-sensitive');

    // Module system (Phase 2)
    Route::get('menu', MenuController::class);
    // The header bell: counts of work waiting for this person here.
    Route::get('attention', AttentionController::class);
    // Countries the platform knows (Phase 6), for pickers and "comes from the country" hints.
    Route::get('countries', CountryController::class);
    Route::get('organizations/{organization}/modules', [ModuleController::class, 'index']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('organizations/{organization}/modules/{module}/enable', [ModuleController::class, 'enable']);
        Route::post('organizations/{organization}/modules/{module}/disable', [ModuleController::class, 'disable']);
        Route::post('organizations/{organization}/modules/{module}/inherit', [ModuleController::class, 'inherit']);
        Route::post('organizations/{organization}/modules/{module}/consent', [ModuleConsentController::class, 'store']);
        Route::delete('organizations/{organization}/modules/{module}/consent', [ModuleConsentController::class, 'destroy']);
        Route::post('organizations/{organization}/modules/{module}/purge', [ModulePurgeController::class, 'store']);
        Route::delete('organizations/{organization}/modules/{module}/purge', [ModulePurgeController::class, 'destroy']);
    });

    // Rule engine (Phase 3)
    Route::get('organizations/{organization}/rules', [OrganizationRuleController::class, 'index']);
    Route::get('organizations/{organization}/rules/{key}', [OrganizationRuleController::class, 'show']);
    Route::get('organizations/{organization}/rules/{key}/history', [OrganizationRuleController::class, 'history']);
    Route::get('organizations/{organization}/rule-approvals', [RuleApprovalController::class, 'index']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::put('organizations/{organization}/rules/{key}', [OrganizationRuleController::class, 'update']);
        Route::delete('organizations/{organization}/rules/{key}', [OrganizationRuleController::class, 'destroy']);
        Route::post('organizations/{organization}/rules/{key}/preview', [OrganizationRuleController::class, 'preview']);
        Route::post('organizations/{organization}/rules/{key}/rollback', [OrganizationRuleController::class, 'rollback']);
        Route::post('organizations/{organization}/rule-approvals/{value}/approve', [RuleApprovalController::class, 'approve'])->middleware('two_factor.recent');
        Route::post('organizations/{organization}/rule-approvals/{value}/reject', [RuleApprovalController::class, 'reject']);
    });
});

Route::middleware(['auth:sanctum', 'partner'])->prefix('partner')->group(function () {
    Route::get('organizations', [PartnerOrganizationController::class, 'index']);
    Route::get('organizations/{organization}', [PartnerOrganizationController::class, 'show']);
    // Break-glass support access (Phase 5B-2).
    Route::get('support-grants', [PartnerSupportController::class, 'index']);
    Route::post('support-grants', [PartnerSupportController::class, 'store'])->middleware('throttle:support-request');
    Route::post('support-grants/{grant}/cancel', [PartnerSupportController::class, 'cancel'])->middleware('throttle:tenancy-sensitive');

    // Partner layer (Phase 5B-1): brand, domains, modules for all clients, client accounts.
    Route::get('brand', [PartnerBrandController::class, 'show']);
    Route::get('domains', [PartnerDomainController::class, 'index']);
    Route::get('modules', [PartnerModuleController::class, 'index']);
    Route::get('clients/{client}/limits', [PartnerClientController::class, 'limits']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::patch('brand', [PartnerBrandController::class, 'update']);
        Route::put('brand/powered-by', [PartnerBrandController::class, 'poweredBy']);
        Route::delete('brand/assets/{kind}', [PartnerBrandController::class, 'destroyAsset']);
        Route::post('domains', [PartnerDomainController::class, 'store']);
        Route::delete('domains/{domain}', [PartnerDomainController::class, 'destroy']);
        Route::put('modules/{module}', [PartnerModuleController::class, 'update']);
        Route::post('clients', [PartnerClientController::class, 'store']);
        Route::patch('clients/{client}/status', [PartnerClientController::class, 'status']);
        Route::put('clients/{client}/limits', [PartnerClientController::class, 'updateLimits']);
    });
    Route::middleware('throttle:partner-heavy')->group(function () {
        Route::post('brand/assets/{kind}', [PartnerBrandController::class, 'storeAsset']);
        Route::post('domains/{domain}/verify', [PartnerDomainController::class, 'verify']);
    });

    // Partner plans and billing (Phase 5B-3).
    Route::get('plans', [PartnerPlansController::class, 'index']);
    Route::get('clients/{client}/subscription', [PartnerSubscriptionController::class, 'show']);
    Route::get('billing', [PartnerBillingController::class, 'summary']);
    Route::get('billing/invoices', [PartnerBillingController::class, 'invoices']);
    Route::get('billing/invoices/{invoice}', [PartnerBillingController::class, 'invoice']);
    Route::get('billing/commissions', [PartnerBillingController::class, 'commissions']);
    Route::get('billing/payouts', [PartnerBillingController::class, 'payouts']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('plans', [PartnerPlansController::class, 'store']);
        Route::patch('plans/{plan}', [PartnerPlansController::class, 'update']);
        Route::post('plans/{plan}/archive', [PartnerPlansController::class, 'archive']);
    });

    Route::get('organizations/{organization}/plan-preview', [PartnerPlanController::class, 'preview']);
    Route::put('organizations/{organization}/plan', [PartnerPlanController::class, 'update'])->middleware('throttle:tenancy-sensitive');

    // API keys for the partner's own systems (Phase 5B-5).
    Route::get('api-keys', [PartnerApiKeyController::class, 'index']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('api-keys', [PartnerApiKeyController::class, 'store'])->middleware('two_factor.recent');
        Route::delete('api-keys/{key}', [PartnerApiKeyController::class, 'revoke']);
    });

    // Clients moving in and out, and the partner's legal documents (Phase 5B-4).
    Route::get('transfers', [PartnerTransferController::class, 'index']);
    Route::get('legal', [PartnerLegalController::class, 'index']);
    Route::get('legal/{kind}/{version}', [PartnerLegalController::class, 'show'])->whereNumber('version');
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('transfer-codes', [PartnerTransferController::class, 'storeCode']);
        Route::delete('transfer-codes/{code}', [PartnerTransferController::class, 'revokeCode']);
        Route::post('transfers/{transfer}/accept', [PartnerTransferController::class, 'accept'])->middleware('two_factor.recent');
        Route::post('transfers/{transfer}/reject', [PartnerTransferController::class, 'reject']);
        Route::post('legal/{kind}', [PartnerLegalController::class, 'publish']);
    });

    // Branded email and SMS (Phase 5B-3b).
    Route::get('messaging', [PartnerMessagingController::class, 'show']);
    Route::get('templates', [PartnerTemplateController::class, 'index']);
    Route::get('templates/{notification}', [PartnerTemplateController::class, 'show']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('messaging/domain', [PartnerMessagingController::class, 'storeDomain']);
        Route::patch('messaging/domain', [PartnerMessagingController::class, 'updateSender']);
        Route::delete('messaging/domain', [PartnerMessagingController::class, 'destroyDomain']);
        Route::post('messaging/sms-sender', [PartnerMessagingController::class, 'storeSmsSender']);
        Route::delete('messaging/sms-sender', [PartnerMessagingController::class, 'destroySmsSender']);
        Route::put('templates/{notification}/{channel}/{locale}', [PartnerTemplateController::class, 'update']);
        Route::delete('templates/{notification}/{channel}/{locale}', [PartnerTemplateController::class, 'destroy']);
    });
    Route::post('templates/{notification}/{channel}/{locale}/preview', [PartnerTemplateController::class, 'preview'])->middleware('throttle:partner-heavy');
    Route::post('messaging/domain/verify', [PartnerMessagingController::class, 'verifyDomain'])->middleware('throttle:partner-heavy');
    Route::middleware('throttle:notification-test')->group(function () {
        Route::post('messaging/test-email', [PartnerMessagingController::class, 'testEmail']);
        Route::post('messaging/test-sms', [PartnerMessagingController::class, 'testSms']);
    });

    Route::get('rules', [PartnerRuleController::class, 'index']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::put('rules/{key}', [PartnerRuleController::class, 'update']);
        Route::delete('rules/{key}', [PartnerRuleController::class, 'destroy']);
        Route::post('rule-approvals/{value}/approve', [PartnerRuleController::class, 'approve'])->middleware('two_factor.recent');
        Route::post('rule-approvals/{value}/reject', [PartnerRuleController::class, 'reject']);
    });
});

/*
| Partner API v1 (Phase 5B-5): a partner's own systems, with an API key
| (Authorization: Bearer osk_...). Per-key rate limit; writes may carry an
| Idempotency-Key header.
*/
Route::prefix('partner/v1')->middleware(['partner.key', 'throttle:partner-api', 'api.idempotent'])->group(function () {
    Route::get('clients', [PartnerApiV1Controller::class, 'clients'])->middleware('api.scope:clients:read');
    Route::get('clients/{client}', [PartnerApiV1Controller::class, 'client'])->middleware('api.scope:clients:read');
    Route::post('clients', [PartnerApiV1Controller::class, 'storeClient'])->middleware('api.scope:clients:write');
    Route::post('clients/{client}/members', [PartnerApiV1Controller::class, 'storeMember'])->middleware('api.scope:members:write');
    Route::get('plans', [PartnerApiV1Controller::class, 'plans'])->middleware('api.scope:plans:read');
    Route::put('clients/{client}/plan', [PartnerApiV1Controller::class, 'changePlan'])->middleware('api.scope:subscriptions:write');
});
