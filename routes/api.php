<?php

use App\Platform\Access\Http\Controllers\PermissionController;
use App\Platform\Access\Http\Controllers\RoleController;
use App\Platform\Modules\Http\Controllers\MenuController;
use App\Platform\Packaging\Http\Controllers\CatalogController;
use App\Platform\Packaging\Http\Controllers\PartnerPlanController;
use App\Platform\Packaging\Http\Controllers\SectorPackageController;
use App\Platform\Packaging\Http\Controllers\UsageController;
use App\Platform\Modules\Http\Controllers\ModuleConsentController;
use App\Platform\Modules\Http\Controllers\ModuleController;
use App\Platform\Modules\Http\Controllers\ModulePurgeController;
use App\Platform\Rules\Http\Controllers\OrganizationRuleController;
use App\Platform\Rules\Http\Controllers\PartnerRuleController;
use App\Platform\Rules\Http\Controllers\RuleApprovalController;
use App\Platform\Tenancy\Http\Controllers\Api\AuthController;
use App\Platform\Tenancy\Http\Controllers\Api\MeController;
use App\Platform\Tenancy\Http\Controllers\Api\MemberController;
use App\Platform\Tenancy\Http\Controllers\Api\OrganizationController;
use App\Platform\Tenancy\Http\Controllers\Api\PartnerOrganizationController;
use Illuminate\Support\Facades\Route;

/*
| Tenancy foundation (Phase 1). The active organization / partner always
| comes from the token (see ResolveOrganization / ResolvePartner).
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:tenancy-login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('context', [AuthController::class, 'enterContext'])->middleware('throttle:tenancy-sensitive');
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::get('me', MeController::class)->middleware('auth:sanctum');

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
        ->middleware('throttle:tenancy-sensitive');

    // Roles and permissions (Phase 4)
    Route::get('organizations/{organization}/permissions', [PermissionController::class, 'index']);
    Route::get('organizations/{organization}/role-templates', [PermissionController::class, 'templates']);
    Route::get('organizations/{organization}/roles', [RoleController::class, 'index']);
    Route::get('organizations/{organization}/roles/{role}', [RoleController::class, 'show']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::post('organizations/{organization}/roles', [RoleController::class, 'store']);
        Route::patch('organizations/{organization}/roles/{role}', [RoleController::class, 'update']);
        Route::delete('organizations/{organization}/roles/{role}', [RoleController::class, 'destroy']);
    });

    // Plan usage and sector packages (Phase 5)
    Route::get('organizations/{organization}/usage', UsageController::class);
    Route::post('organizations/{organization}/sector-package', [SectorPackageController::class, 'store'])
        ->middleware('throttle:tenancy-sensitive');

    // Module system (Phase 2)
    Route::get('menu', MenuController::class);
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
        Route::post('organizations/{organization}/rule-approvals/{value}/approve', [RuleApprovalController::class, 'approve']);
        Route::post('organizations/{organization}/rule-approvals/{value}/reject', [RuleApprovalController::class, 'reject']);
    });
});

Route::middleware(['auth:sanctum', 'partner'])->prefix('partner')->group(function () {
    Route::get('organizations', [PartnerOrganizationController::class, 'index']);
    Route::get('organizations/{organization}', [PartnerOrganizationController::class, 'show']);
    Route::get('organizations/{organization}/plan-preview', [PartnerPlanController::class, 'preview']);
    Route::put('organizations/{organization}/plan', [PartnerPlanController::class, 'update'])->middleware('throttle:tenancy-sensitive');

    Route::get('rules', [PartnerRuleController::class, 'index']);
    Route::middleware('throttle:tenancy-sensitive')->group(function () {
        Route::put('rules/{key}', [PartnerRuleController::class, 'update']);
        Route::delete('rules/{key}', [PartnerRuleController::class, 'destroy']);
        Route::post('rule-approvals/{value}/approve', [PartnerRuleController::class, 'approve']);
        Route::post('rule-approvals/{value}/reject', [PartnerRuleController::class, 'reject']);
    });
});
