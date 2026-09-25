<?php

use App\Platform\Tenancy\Http\Controllers\Api\AuthController;
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
});

Route::middleware(['auth:sanctum', 'partner'])->prefix('partner')->group(function () {
    Route::get('organizations', [PartnerOrganizationController::class, 'index']);
    Route::get('organizations/{organization}', [PartnerOrganizationController::class, 'show']);
});
